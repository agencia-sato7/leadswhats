<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCampaignIntelligenceReport;
use App\Services\Domain\CampaignIntelligenceService;
use App\Services\EffectiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CampaignIntelligenceController extends Controller
{
    public function preview(Request $request, CampaignIntelligenceService $service, EffectiveTenantContext $tenantContext): JsonResponse
    {
        $dates = $this->dates($request);
        try {
            return response()->json($service->previewForCompany(
                $tenantContext->companyId($request),
                $dates['start_date'],
                $dates['end_date'],
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function index(Request $request, CampaignIntelligenceService $service, EffectiveTenantContext $tenantContext): JsonResponse
    {
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        return response()->json($service->listForCompany(
            $tenantContext->companyId($request),
            (int) ($validated['page'] ?? 1),
        ));
    }

    public function store(Request $request, CampaignIntelligenceService $service): JsonResponse
    {
        $dates = $this->dates($request);
        try {
            $requested = $service->requestReport(
                (int) $request->user()->company_id,
                (int) $request->user()->id,
                $dates['start_date'],
                $dates['end_date'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $report = $requested['report'];
        if (!$requested['reused']) {
            ProcessCampaignIntelligenceReport::dispatch($report->id);
            $report->refresh();
        }

        $status = $report->status === 'completed' ? 200 : 202;
        return response()->json([
            'message' => $report->status === 'completed'
                ? 'Análise salva encontrada para este período.'
                : 'Análise da campanha adicionada à fila.',
            'reused' => (bool) $requested['reused'],
            'data' => $service->reportPayload($report->loadMissing('requestedBy'), true),
        ], $status);
    }

    public function show(Request $request, int $report, CampaignIntelligenceService $service, EffectiveTenantContext $tenantContext): JsonResponse
    {
        $data = $service->reportForCompany($tenantContext->companyId($request), $report);
        return $data
            ? response()->json(['data' => $data])
            : response()->json(['message' => 'Relatório não encontrado.'], 404);
    }

    public function leads(Request $request, int $report, CampaignIntelligenceService $service, EffectiveTenantContext $tenantContext): JsonResponse
    {
        $filters = $request->validate([
            'cohort' => ['sometimes', 'in:new,rescued'],
            'owner_user_id' => ['sometimes', 'integer', 'min:0'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $data = $service->leadsForReport($tenantContext->companyId($request), $report, $filters);
        return $data
            ? response()->json($data)
            : response()->json(['message' => 'Relatório não encontrado.'], 404);
    }

    /** @return array{start_date:string,end_date:string} */
    private function dates(Request $request): array
    {
        return $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
        ]);
    }
}
