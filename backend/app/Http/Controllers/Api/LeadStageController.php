<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\KanbanMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadStageController extends Controller
{
    public function update(Request $request, int $leadId, KanbanMovementService $kanbanMovementService): JsonResponse
    {
        $validated = $request->validate([
            'kanban_column_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $kanbanMovementService->moveLeadToColumn(
            (int) $request->user()->company_id,
            $leadId,
            (int) $validated['kanban_column_id'],
            (int) $request->user()->id,
            $validated['reason'] ?? null,
        );

        return response()->json([
            'message' => 'Lead stage updated successfully.',
            'data' => $movement,
        ]);
    }
}
