<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyBusinessSetting;
use App\Models\Lead;
use App\Services\CompanySettingsService;
use App\Services\Domain\MarketingIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingIntelligenceController extends Controller
{
    public function __construct(
        private readonly MarketingIntelligenceService $intelligenceService,
        private readonly CompanySettingsService $settings,
    ) {
    }

    public function classifySourceByAi(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $result = $this->intelligenceService->classifyLeadSourceByAi($lead);

        return response()->json([
            'message' => $result['applied']
                ? 'Origem classificada pela IA com sucesso.'
                : 'A IA não conseguiu uma origem confiável; o lead permanece como está.',
            'data' => $result,
        ]);
    }

    public function analyzeCreativeByAi(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $result = $this->intelligenceService->analyzeLeadCreative($lead);

        return response()->json([
            'message' => $result['analyzed']
                ? 'Criativo analisado com sucesso pela IA.'
                : $result['reason'],
            'data' => $result,
        ]);
    }

    public function sourcesSummary(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        $funnel = $this->intelligenceService->sourceSummaryForCompany($companyId);

        $sources = $this->intelligenceService->validSources();

        $bySource = [];
        foreach ($sources as $source) {
            $bySource[$source] = [
                'source' => $source,
                'total_leads' => 0,
                'stages' => [],
            ];
        }

        foreach ($funnel as $row) {
            $sourceKey = (string) $row['source'];
            if (!isset($bySource[$sourceKey])) {
                $bySource[$sourceKey] = [
                    'source' => $sourceKey,
                    'total_leads' => 0,
                    'stages' => [],
                ];
            }

            $bySource[$sourceKey]['total_leads'] += $row['count'];
            $bySource[$sourceKey]['stages'][] = [
                'stage_name' => $row['stage_name'],
                'count' => $row['count'],
            ];
        }

        return response()->json([
            'data' => [
                'funnel' => $funnel,
                'by_source' => array_values($bySource),
            ],
        ]);
    }

    public function creativeRanking(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        return response()->json([
            'data' => $this->intelligenceService->creativeRankingForCompany($companyId),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lookalike_export_stage_ids' => ['present', 'array'],
            'lookalike_export_stage_ids.*' => ['integer', 'min:1'],
        ]);

        $companyId = (int) $request->user()->company_id;

        CompanyBusinessSetting::updateOrCreate(
            ['company_id' => $companyId],
            ['lookalike_export_stage_ids' => array_values($validated['lookalike_export_stage_ids'])],
        );

        return response()->json([
            'message' => 'Configurações de inteligência atualizadas.',
            'data' => [
                'lookalike_export_stage_ids' => array_values($validated['lookalike_export_stage_ids']),
            ],
        ]);
    }

    public function lookalikeExport(Request $request): StreamedResponse
    {
        $companyId = (int) $request->user()->company_id;
        $stageIds = $request->query('stage_ids');

        $resolvedStageIds = is_string($stageIds) && trim($stageIds) !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $stageIds)), fn ($id) => $id > 0))
            : $this->settings->lookalikeExportStageIds($companyId);

        $rows = $this->intelligenceService->lookalikeExportRowsForCompany($companyId, $resolvedStageIds);

        $fileName = 'lookalike_export_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(static function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fputcsv($output, ['phone', 'name', 'source', 'current_stage', 'creative_id', 'created_at']);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['phone'] ?? null,
                    $row['name'] ?? null,
                    $row['source'] ?? null,
                    $row['current_stage'] ?? null,
                    $row['creative_id'] ?? null,
                    $row['created_at'] ?? null,
                ]);
            }

            fclose($output);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authorizeLead(Request $request, Lead $lead): void
    {
        abort_unless((int) $lead->company_id === (int) $request->user()->company_id, 403, 'Lead não pertence à sua empresa.');
    }
}
