<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\PipelineKanbanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    public function index(Request $request, PipelineKanbanService $pipelineKanbanService): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $pipelines = $pipelineKanbanService->listPipelinesForCompany($companyId);

        return response()->json(["data" => $pipelines]);
    }

    public function kanban(Request $request, int $pipelineId, PipelineKanbanService $pipelineKanbanService): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $kanban = $pipelineKanbanService->kanbanForPipeline($companyId, $pipelineId);

        if (!$kanban) {
            abort(404, "Pipeline não encontrado.");
        }

        return response()->json(["data" => $kanban]);
    }
}
