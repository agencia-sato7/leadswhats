<?php

namespace App\Services;

use App\Models\CampaignIntelligenceReport;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Domain\CampaignIntelligenceService;
use App\Services\Intelligence\FakeCampaignAnalyzer;
use Carbon\CarbonImmutable;
use RuntimeException;

class DemoCampaignIntelligenceService
{
    public function __construct(
        private readonly DemoBootstrapService $demoBootstrap,
        private readonly CompanySettingsService $settings,
        private readonly FakeCampaignAnalyzer $analyzer,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('A campanha de demonstração não pode ser criada em production.');
        }

        $this->demoBootstrap->run();

        $company = Company::query()->where('slug', 'empresa-demo')->firstOrFail();
        $manager = User::query()->where('company_id', $company->id)->where('email', 'gestor@empresa.local')->firstOrFail();
        $owner = User::query()->where('company_id', $company->id)->where('email', 'marina@empresa.local')->firstOrFail();
        $timezone = $this->settings->timezone((int) $company->id);
        $end = CarbonImmutable::now($timezone)->startOfDay()->subDay();
        $start = $end->subDays(6);

        $this->seedRescuedLead($company, $owner, $start, $end);

        $campaignService = new CampaignIntelligenceService($this->analyzer, $this->settings);
        $requested = $campaignService->requestReport(
            (int) $company->id,
            (int) $manager->id,
            $start->toDateString(),
            $end->toDateString(),
        );

        /** @var CampaignIntelligenceReport $report */
        $report = $requested['report'];
        if ($report->status !== 'completed') {
            $campaignService->processReport($report);
        }

        $report->refresh()->loadMissing('requestedBy');

        return [
            'company_id' => (int) $company->id,
            'company_name' => (string) $company->name,
            'report_id' => (int) $report->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'status' => (string) $report->status,
            'reused' => (bool) $requested['reused'],
            'metrics' => $report->metrics,
            'result' => $report->result,
        ];
    }

    private function seedRescuedLead(
        Company $company,
        User $owner,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): void {
        $pipeline = Pipeline::query()
            ->where('company_id', $company->id)
            ->where('is_default', true)
            ->firstOrFail();
        $column = KanbanColumn::query()
            ->where('company_id', $company->id)
            ->where('pipeline_id', $pipeline->id)
            ->where('name', 'Avaliação Agendada')
            ->firstOrFail();

        $createdAt = $start->subDays(14)->setTime(10, 0)->utc();
        $rescueAt = $end->subDay()->setTime(10, 0)->utc();
        $lastMessageAt = $rescueAt->addMinutes(24);

        $lead = Lead::query()->updateOrCreate(
            ['company_id' => $company->id, 'phone_e164' => '+5511911101099'],
            [
                'owner_user_id' => $owner->id,
                'name' => 'Marcelo Pires',
                'source' => 'instagram',
                'source_method' => 'auto',
                'source_updated_at' => $createdAt,
                'creative_id' => 'instagram-resgate-implantes-marcelo',
                'creative_url' => 'https://instagram.com/p/oralsin-resgate-demo',
                'campaign_name' => 'Instagram | Retomada de Implantes',
                'is_repeat_lead' => true,
                'first_inbound_at' => $rescueAt->addMinutes(8),
                'last_inbound_at' => $lastMessageAt,
                'last_outbound_at' => $rescueAt->addMinutes(16),
                'first_response_seconds' => null,
                'metadata' => [
                    'demo' => true,
                    'fixture' => 'campaign-presentation',
                    'scenario' => 'rescued-lead',
                ],
            ]
        );
        $lead->forceFill(['created_at' => $createdAt])->saveQuietly();

        $conversation = Conversation::query()->updateOrCreate(
            ['company_id' => $company->id, 'lead_id' => $lead->id],
            [
                'owner_user_id' => $owner->id,
                'status' => 'active',
                'started_at' => $rescueAt,
                'last_message_at' => $lastMessageAt,
                'closed_at' => null,
            ]
        );
        $conversation->forceFill(['created_at' => $createdAt])->saveQuietly();

        $messages = [
            ['outbound', 'Olá, Marcelo! Você conversou com a gente sobre implantes há algumas semanas. Posso te ajudar a retomar sua avaliação?', true],
            ['inbound', 'Oi, Marina. Sim, quero retomar. Agora consigo organizar melhor os horários.', false],
            ['outbound', 'Ótimo! Tenho quinta às 15h ou sexta às 10h para uma avaliação. Qual funciona melhor?', false],
            ['inbound', 'Quinta às 15h fica perfeito. Pode agendar para mim.', false],
        ];

        foreach ($messages as $index => [$direction, $body, $isRescue]) {
            $sentAt = $rescueAt->addMinutes($index * 8);
            $message = Message::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'provider' => 'fake',
                    'external_message_id' => sprintf('demo-campaign-rescue-%d', $index + 1),
                ],
                [
                    'lead_id' => $lead->id,
                    'conversation_id' => $conversation->id,
                    'direction' => $direction,
                    'channel' => 'text',
                    'body' => $body,
                    'sent_at' => $sentAt,
                    'raw_payload' => ['demo' => true, 'fixture' => 'campaign-presentation'],
                    'is_rescue' => $isRescue,
                    'metadata' => ['fixture' => 'campaign-presentation', 'classification' => 'lead_recorrente'],
                ]
            );
            $message->forceFill(['created_at' => $sentAt])->saveQuietly();
        }

        LeadStageHistory::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'lead_id' => $lead->id,
                'move_source' => 'system',
                'reason' => 'Campanha demo: resgate convertido em avaliação agendada.',
            ],
            [
                'from_column_id' => null,
                'to_column_id' => $column->id,
                'moved_by_user_id' => null,
                'moved_at' => $lastMessageAt,
            ]
        );
    }
}
