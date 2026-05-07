<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\KanbanColumnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class KanbanColumnController extends Controller
{
    public function store(Request $request, int $pipelineId, KanbanColumnService $kanbanColumnService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'position' => ['required', 'integer', 'min:1'],
            'rule' => ['nullable', 'string'],
        ]);

        $column = $kanbanColumnService->createColumn(
            (int) $request->user()->company_id,
            $pipelineId,
            $validated,
        );

        return response()->json([
            'message' => 'Kanban column created successfully.',
            'data' => [
                'id' => $column->id,
                'pipeline_id' => $column->pipeline_id,
                'name' => $column->name,
                'position' => $column->position,
                'rule' => $column->rule_prompt,
            ],
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, int $columnId, KanbanColumnService $kanbanColumnService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'position' => ['sometimes', 'integer', 'min:1'],
            'rule' => ['sometimes', 'nullable', 'string'],
        ]);

        $column = $kanbanColumnService->updateColumn(
            (int) $request->user()->company_id,
            $columnId,
            $validated,
        );

        return response()->json([
            'message' => 'Kanban column updated successfully.',
            'data' => [
                'id' => $column->id,
                'pipeline_id' => $column->pipeline_id,
                'name' => $column->name,
                'position' => $column->position,
                'rule' => $column->rule_prompt,
            ],
        ]);
    }

    public function destroy(Request $request, int $columnId, KanbanColumnService $kanbanColumnService): Response
    {
        $kanbanColumnService->deleteColumn((int) $request->user()->company_id, $columnId);

        return response()->noContent();
    }
}
