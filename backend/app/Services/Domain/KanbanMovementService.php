<?php

namespace App\Services\Domain;

use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use Illuminate\Validation\ValidationException;

class KanbanMovementService
{
    /**
     * @return array{lead_id:int,kanban_column_id:int,moved_by_user_id:int,movement_type:string,history_created:bool}
     */
    public function moveLeadToColumn(
        int $companyId,
        int $leadId,
        int $kanbanColumnId,
        int $movedByUserId,
        ?string $reason,
        string $movementSource = 'manual',
    ): array {
        if (! in_array($movementSource, ['manual', 'ai_recommendation_accepted'], true)) {
            throw ValidationException::withMessages([
                'movement_source' => 'Origem de movimentação inválida.',
            ]);
        }

        $lead = Lead::query()
            ->where('company_id', $companyId)
            ->findOrFail($leadId, ['id']);

        $targetColumn = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->findOrFail($kanbanColumnId, ['id', 'pipeline_id']);

        $latestStage = LeadStageHistory::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $lead->id)
            ->orderByDesc('id')
            ->first(['id', 'to_column_id']);

        if ($latestStage) {
            $currentPipelineId = KanbanColumn::query()
                ->where('company_id', $companyId)
                ->whereKey($latestStage->to_column_id)
                ->value('pipeline_id');

            if ($currentPipelineId !== null && (int) $currentPipelineId !== (int) $targetColumn->pipeline_id) {
                throw ValidationException::withMessages([
                    'kanban_column_id' => 'A etapa de destino deve pertencer ao pipeline atual do lead.',
                ]);
            }
        }

        if ($latestStage && (int) $latestStage->to_column_id === (int) $targetColumn->id) {
            return [
                'lead_id' => $lead->id,
                'kanban_column_id' => $targetColumn->id,
                'moved_by_user_id' => $movedByUserId,
                'movement_type' => $movementSource,
                'history_created' => false,
            ];
        }

        LeadStageHistory::query()->create([
            'company_id' => $companyId,
            'lead_id' => $lead->id,
            'from_column_id' => $latestStage?->to_column_id,
            'to_column_id' => $targetColumn->id,
            'moved_by_user_id' => $movedByUserId,
            'move_source' => $movementSource,
            'reason' => $reason,
            'moved_at' => now(),
        ]);

        return [
            'lead_id' => $lead->id,
            'kanban_column_id' => $targetColumn->id,
            'moved_by_user_id' => $movedByUserId,
            'movement_type' => $movementSource,
            'history_created' => true,
        ];
    }
}
