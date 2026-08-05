<?php

namespace App\Services\Domain;

use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadCreativeAnalysis;
use App\Models\Message;
use App\Services\CompanySettingsService;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the Marketing Intelligence area:
 *  - AI source classification from first message.
 *  - AI ad creative analysis from tracked links.
 *  - Funnel-by-origin summary (creative ranking + source matrix).
 *  - Lookalike CSV export rows (final funnel stages).
 */
class MarketingIntelligenceService
{
    public function __construct(
        private readonly OpenAiIntelligenceService $openAi,
        private readonly LeadSourceService $leadSourceService,
        private readonly CompanySettingsService $settings,
    ) {
    }

    /**
     * Classifies a lead's origin using AI, reading its first inbound message.
     * If the AI produces a confident non-unknown source, it is applied.
     *
     * @return array{applied:bool,source:string,confidence:float,reason:string}
     */
    public function classifyLeadSourceByAi(Lead $lead): array
    {
        $firstMessage = Message::query()
            ->where('company_id', $lead->company_id)
            ->where('lead_id', $lead->id)
            ->where('direction', 'inbound')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->value('body');

        if (!$firstMessage) {
            return [
                'applied' => false,
                'source' => OpenAiIntelligenceService::SOURCE_UNKNOWN,
                'confidence' => 0.0,
                'reason' => 'Sem mensagem inbound para análise de origem.',
            ];
        }

        $result = $this->openAi->classifySourceFromFirstMessage((string) $firstMessage);

        if ($result['source'] === OpenAiIntelligenceService::SOURCE_UNKNOWN || $result['confidence'] < 0.4) {
            return [
                'applied' => false,
                'source' => $result['source'],
                'confidence' => $result['confidence'],
                'reason' => $result['reason'],
            ];
        }

        $this->leadSourceService->applyAiSource($lead, $result['source'], $result['reason']);

        return [
            'applied' => true,
            'source' => $result['source'],
            'confidence' => $result['confidence'],
            'reason' => $result['reason'],
        ];
    }

    /**
     * Analyzes an ad creative for the lead. If a tracked URL is found in the
     * first inbound message, extracts the creative ID and asks the AI to describe it.
     *
     * @return array{analyzed:bool,creative_id:string|null,platform:string|null,status:string,reason:string}
     */
    public function analyzeLeadCreative(Lead $lead): array
    {
        $firstMessage = Message::query()
            ->where('company_id', $lead->company_id)
            ->where('lead_id', $lead->id)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->value('body');

        $url = $this->extractFirstUrl((string) $firstMessage);

        if (!$url) {
            return [
                'analyzed' => false,
                'creative_id' => null,
                'platform' => null,
                'status' => 'no_url',
                'reason' => 'Nenhum link rastreado na primeira mensagem.',
            ];
        }

        $creativeId = $this->openAi->extractCreativeIdFromUrl($url);

        $analysis = $this->openAi->analyzeCreative($url);

        LeadCreativeAnalysis::create([
            'company_id' => $lead->company_id,
            'lead_id' => $lead->id,
            'creative_id' => $analysis['creative_id'] ?? $creativeId,
            'creative_url' => $url,
            'platform' => $analysis['platform'],
            'description' => $analysis['description'],
            'headline' => $analysis['headline'],
            'cta' => $analysis['cta'],
            'image_url' => $analysis['image_url'],
            'status' => $analysis['status'],
            'metadata' => ['method' => $analysis['status']],
            'analyzed_at' => now(),
        ]);

        $lead->creative_id = $analysis['creative_id'] ?? $creativeId;
        $lead->creative_url = $url;
        $lead->creative_description = $analysis['description'] !== null ? substr($analysis['description'], 0, 65535) : null;
        $lead->save();

        return [
            'analyzed' => $analysis['status'] === 'analyzed',
            'creative_id' => $lead->creative_id,
            'platform' => $analysis['platform'],
            'status' => $analysis['status'],
            'reason' => 'Análise de criativo registrada.',
        ];
    }

    /**
     * Builds the funnel matrix (source x stage) for a company, reusing the same
     * logic shape as DashboardMetricsService for consistency.
     *
     * @return array<int, array{source:string,stage_name:string,count:int}>
     */
    public function sourceSummaryForCompany(int $companyId): array
    {
        $latestStageIds = DB::table('lead_stage_histories')
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->groupBy('lead_id');

        $latestStages = DB::table('lead_stage_histories as lsh')
            ->select('lsh.lead_id', 'lsh.to_column_id')
            ->joinSub($latestStageIds, 'latest', 'lsh.id', '=', 'latest.id');

        return DB::table('leads as l')
            ->joinSub($latestStages, 'latest_stage', 'l.id', '=', 'latest_stage.lead_id')
            ->join('kanban_columns as kc', 'kc.id', '=', 'latest_stage.to_column_id')
            ->select('l.source', 'kc.name as stage_name', DB::raw('count(*) as count'))
            ->where('l.company_id', $companyId)
            ->groupBy('l.source', 'kc.name')
            ->get()
            ->map(static function ($row): array {
                return [
                    'source' => (string) $row->source,
                    'stage_name' => (string) $row->stage_name,
                    'count' => (int) $row->count,
                ];
            })
            ->all();
    }

    /**
     * Ranks ad creatives by how many leads they generated and how many of those
     * reached a terminal (final) funnel stage, making it easy to see which ad
     * closes the "easiest" sales.
     *
     * @return array<int, array<string, mixed>>
     */
    public function creativeRankingForCompany(int $companyId): array
    {
        $creativeGroups = DB::table('lead_creative_analyses')
            ->select('creative_id', 'creative_url', 'platform', 'description', 'headline', 'cta', 'image_url')
            ->where('company_id', $companyId)
            ->whereNotNull('creative_id')
            ->groupBy('creative_id', 'creative_url', 'platform', 'description', 'headline', 'cta', 'image_url')
            ->get();

        $ranking = [];

        foreach ($creativeGroups as $group) {
            $creativeId = (string) $group->creative_id;

            $leadsCount = DB::table('leads')
                ->where('company_id', $companyId)
                ->where('creative_id', $creativeId)
                ->count();

            $terminalColumnIds = KanbanColumn::query()
                ->where('company_id', $companyId)
                ->where('is_terminal', true)
                ->pluck('id');

            $terminalLeads = 0;
            if ($terminalColumnIds->isNotEmpty()) {
                $terminalLeads = DB::table('leads as l')
                    ->join('lead_stage_histories as lsh', 'lsh.lead_id', '=', 'l.id')
                    ->where('l.company_id', $companyId)
                    ->where('l.creative_id', $creativeId)
                    ->whereIn('lsh.to_column_id', $terminalColumnIds)
                    ->count();
            }

            $ranking[] = [
                'creative_id' => $creativeId,
                'creative_url' => (string) $group->creative_url,
                'platform' => $group->platform,
                'description' => $group->description,
                'headline' => $group->headline,
                'cta' => $group->cta,
                'image_url' => $group->image_url,
                'leads_count' => (int) $leadsCount,
                'terminal_leads' => (int) $terminalLeads,
            ];
        }

        usort($ranking, static function (array $a, array $b): int {
            return ($b['terminal_leads'] ?? 0) <=> ($a['terminal_leads'] ?? 0)
                ?: ($b['leads_count'] ?? 0) <=> ($a['leads_count'] ?? 0);
        });

        return $ranking;
    }

    /**
     * Returns leads that reached the configured final funnel stages,
     * formatted for a Lookalike CSV export (phone + data).
     *
     * @param int[] $stageIds
     * @return array<int, array<string, mixed>>
     */
    public function lookalikeExportRowsForCompany(int $companyId, array $stageIds): array
    {
        if ($stageIds === []) {
            $stageIds = $this->settings->lookalikeExportStageIds($companyId);
        }

        if ($stageIds === []) {
            return [];
        }

        $latestStageIds = DB::table('lead_stage_histories')
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->groupBy('lead_id');

        $latestStages = DB::table('lead_stage_histories as lsh')
            ->select('lsh.lead_id', 'lsh.to_column_id')
            ->joinSub($latestStageIds, 'latest', 'lsh.id', '=', 'latest.id');

        return DB::table('leads as l')
            ->joinSub($latestStages, 'latest_stage', 'l.id', '=', 'latest_stage.lead_id')
            ->leftJoin('kanban_columns as kc', 'kc.id', '=', 'latest_stage.to_column_id')
            ->select(
                'l.id as lead_id',
                'l.name',
                'l.phone_e164 as phone',
                'l.source',
                'kc.name as current_stage',
                'l.creative_id',
                'l.created_at'
            )
            ->where('l.company_id', $companyId)
            ->whereIn('latest_stage.to_column_id', $stageIds)
            ->orderBy('l.created_at')
            ->get()
            ->map(static function ($row): array {
                return [
                    'lead_id' => (int) $row->lead_id,
                    'name' => $row->name,
                    'phone' => $row->phone,
                    'source' => $row->source,
                    'current_stage' => $row->current_stage,
                    'creative_id' => $row->creative_id,
                    'created_at' => optional($row->created_at)->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return string[]
     */
    public function validSources(): array
    {
        return [
            OpenAiIntelligenceService::SOURCE_FACEBOOK,
            OpenAiIntelligenceService::SOURCE_INSTAGRAM,
            OpenAiIntelligenceService::SOURCE_GOOGLE,
            OpenAiIntelligenceService::SOURCE_TIKTOK,
            OpenAiIntelligenceService::SOURCE_INDICACAO,
            OpenAiIntelligenceService::SOURCE_UNKNOWN,
        ];
    }

    private function extractFirstUrl(?string $text): ?string
    {
        if (!$text) {
            return null;
        }

        if (preg_match('/https?:\/\/[^\s]+/i', $text, $matches)) {
            return rtrim($matches[0], '.,;!?"\'');
        }

        return null;
    }
}
