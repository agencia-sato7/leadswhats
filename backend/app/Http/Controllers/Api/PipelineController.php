<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\PipelineKanbanService;
use App\Services\EffectiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    public function index(
        Request $request,
        PipelineKanbanService $pipelineKanbanService,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $companyId = $tenantContext->companyId($request);

        $pipelines = $pipelineKanbanService->listPipelinesForCompany($companyId);

        return response()->json(['data' => $pipelines]);
    }

    public function kanban(
        Request $request,
        int $pipelineId,
        PipelineKanbanService $pipelineKanbanService,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $companyId = $tenantContext->companyId($request);
        $kanban = $pipelineKanbanService->kanbanForPipeline($companyId, $pipelineId);

        if (! $kanban) {
            abort(404, 'Pipeline não encontrado.');
        }

        return response()->json(['data' => $kanban]);
    }
}
