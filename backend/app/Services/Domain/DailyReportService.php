<?php

namespace App\Services\Domain;

use App\Contracts\Intelligence\DailyReportAnalyzer;
use App\Mail\DailyReportMail;
use App\Models\Company;
use App\Models\ConversationQualityScore;
use App\Models\DailyReport;
use App\Models\Lead;
use App\Services\CompanySettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DailyReportService
{
    public const DEFAULT_SEND_TIME = '18:30';

    /**
     * Motivo registrado quando a clínica ainda não tem e-mail de relatório
     * cadastrado na Central da Agência (status "skipped", sem chamada de IA).
     */
    public const SKIP_REASON_NO_RECIPIENT = 'Nenhum e-mail de relatório diário cadastrado para a clínica.';

    public function __construct(
        private readonly DashboardMetricsService $dashboardMetricsService,
        private readonly CompanySettingsService $companySettings,
        private readonly DailyReportAnalyzer $analyzer,
    ) {}

    /**
     * Gera e envia o relatório diário de uma empresa, de forma idempotente.
     * Retorna null quando já existe relatório enviado para a data.
     */
    public function generateForCompany(Company $company, ?CarbonImmutable $date = null, bool $force = false): ?DailyReport
    {
        $date ??= CarbonImmutable::today($this->companyTimezone($company));

        $existing = DailyReport::query()
            ->where('company_id', $company->id)
            // whereDate normaliza a data (a coluna é DATE no Postgres e guarda
            // "Y-m-d 00:00:00" no SQLite usado pelos testes).
            ->whereDate('report_date', $date->toDateString())
            ->first();

        if ($existing !== null && $existing->status === 'sent' && ! $force) {
            return null;
        }

        $recipients = $this->recipientsFor($company->id);

        $report = $existing ?? new DailyReport([
            'company_id' => $company->id,
            'report_date' => $date->toDateString(),
        ]);

        // Sem e-mail cadastrado no cadastro da clínica não chamamos a IA nem
        // enfileiramos o envio: registramos "skipped" para rastreabilidade. Como
        // só "sent" curto-circuita acima, cadastrar o e-mail depois, no mesmo
        // dia, ainda gera o relatório normalmente.
        if ($recipients === []) {
            $report->recipients = [];
            $report->status = 'skipped';
            $report->error_message = self::SKIP_REASON_NO_RECIPIENT;
            $report->sent_at = null;
            $report->save();

            return $report;
        }

        $metrics = $this->dashboardMetricsService->summaryForCompany($company->id);
        $quality = $this->qualitySummaryForDate($company->id, $date);
        $metricValues = $metrics['metrics'] ?? [];
        $metricValues['previous_new_leads'] = $this->previousDayNewLeads($company->id, $date);

        $aiReport = $this->analyzer->generate([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug ?? null,
            ],
            'report_date' => $date->toDateString(),
            'metrics' => $metricValues,
            'quality' => $quality,
            'team' => $metrics['team_performance'] ?? [],
        ]);

        $report->fill([
            'metrics' => $metricValues,
            'quality' => $quality,
            'executive_summary' => $aiReport['executive_summary'] ?? null,
            'overall_verdict' => $aiReport['overall_verdict'] ?? null,
            'report_payload' => $aiReport,
            'recipients' => $recipients,
        ]);

        try {
            // O Mailable usa SerializesModels: o registro precisa existir no banco
            // antes de entrar na fila, senão a restauração falha no worker.
            $report->status = 'pending';
            $report->sent_at = null;
            $report->error_message = null;
            $report->save();

            Mail::to($recipients)->queue(new DailyReportMail($company, $report));

            $report->status = 'sent';
            $report->sent_at = now();
            $report->error_message = null;
        } catch (Throwable $exception) {
            $report->status = 'failed';
            $report->error_message = $exception->getMessage();

            Log::error('Falha ao enfileirar relatório diário.', [
                'company_id' => $company->id,
                'report_date' => $report->report_date,
                'error' => $exception->getMessage(),
            ]);
        }

        $report->save();

        return $report;
    }

    /**
     * Roda o relatório para as empresas ativas cuja hora local bate com o horário configurado.
     *
     * @return array<string, int>
     */
    public function runScheduled(string $sendTime = self::DEFAULT_SEND_TIME): array
    {
        $summary = ['sent' => 0, 'skipped' => 0, 'without_recipient' => 0, 'failed' => 0];

        Company::query()->where('active', true)->chunkById(100, function ($companies) use (&$summary, $sendTime): void {
            foreach ($companies as $company) {
                $timezone = $this->companyTimezone($company);
                $localNow = CarbonImmutable::now($timezone);

                if ($localNow->format('H:i') !== $sendTime) {
                    $summary['skipped']++;

                    continue;
                }

                $report = $this->generateForCompany($company, CarbonImmutable::today($timezone));

                if ($report === null || $report->status === 'sent') {
                    $summary['sent']++;
                } elseif ($report->status === 'skipped') {
                    $summary['without_recipient']++;
                } else {
                    $summary['failed']++;
                }
            }
        });

        return $summary;
    }

    /**
     * Pontuações de qualidade da IA para a data (último snapshot de cada conversa).
     *
     * @return array<string, mixed>
     */
    private function qualitySummaryForDate(int $companyId, CarbonImmutable $date): array
    {
        $latestScoreIds = ConversationQualityScore::query()
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->whereDate('created_at', $date->toDateString())
            ->groupBy('conversation_id');

        $scores = ConversationQualityScore::query()
            ->whereIn('id', $latestScoreIds)
            ->get(['score', 'criteria_scores']);

        if ($scores->isEmpty()) {
            return [
                'analyzed_conversations' => 0,
                'average_score' => null,
                'low_quality_conversations' => 0,
                'criteria_averages' => [],
            ];
        }

        $criteriaKeys = $scores
            ->flatMap(fn (ConversationQualityScore $score): array => array_keys($score->criteria_scores ?? []))
            ->unique()
            ->values();

        $criteriaAverages = $criteriaKeys->mapWithKeys(function (string $criterion) use ($scores): array {
            $values = $scores
                ->map(fn (ConversationQualityScore $score) => $score->criteria_scores[$criterion] ?? null)
                ->filter(fn ($value): bool => is_numeric($value));

            return [$criterion => $values->isEmpty() ? null : round((float) $values->avg(), 1)];
        })->all();

        return [
            'analyzed_conversations' => $scores->count(),
            'average_score' => round((float) $scores->avg('score'), 1),
            'low_quality_conversations' => $scores->where('score', '<', 60)->count(),
            'criteria_averages' => $criteriaAverages,
        ];
    }

    /**
     * Volume de novos leads do dia anterior, usado como base de comparação pela IA.
     */
    private function previousDayNewLeads(int $companyId, CarbonImmutable $date): int
    {
        return Lead::query()
            ->where('company_id', $companyId)
            ->whereDate('created_at', $date->subDay()->toDateString())
            ->where('is_repeat_lead', false)
            ->count();
    }

    /**
     * Destinatário único: o e-mail cadastrado no cadastro da clínica (Central da
     * Agência). Usuários admin/gestor não têm relação com o envio do relatório.
     *
     * @return list<string>
     */
    private function recipientsFor(int $companyId): array
    {
        $recipient = $this->companySettings->dailyReportRecipient($companyId);

        return $recipient === null || $recipient === '' ? [] : [$recipient];
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = $company->businessSetting()->value('timezone');

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return is_string($company->timezone) && $company->timezone !== ''
            ? $company->timezone
            : 'America/Sao_Paulo';
    }
}
