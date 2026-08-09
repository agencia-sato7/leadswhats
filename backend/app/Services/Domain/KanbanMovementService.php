<?php

namespace App\Services\Domain;

use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;

class KanbanMovementService
{
    /**
     * @return array{lead_id:int,kanban_column_id:int,moved_by_user_id:int,movement_type:string,history_created:bool}
     */
    public function moveLeadToColumn(int $companyId, int $leadId, int $kanbanColumnId, int $movedByUserId, ?string $reason): array
    {
        $lead = Lead::query()
            ->where('company_id', $companyId)
            ->findOrFail($leadId, ['id']);

        $targetColumn = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->findOrFail($kanbanColumnId, ['id']);

        $latestStage = LeadStageHistory::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $lead->id)
            ->orderByDesc('id')
            ->first(['id', 'to_column_id']);

        if ($latestStage && (int) $latestStage->to_column_id === (int) $targetColumn->id) {
            return [
                'lead_id' => $lead->id,
                'kanban_column_id' => $targetColumn->id,
                'moved_by_user_id' => $movedByUserId,
                'movement_type' => 'manual',
                'history_created' => false,
            ];
        }

        LeadStageHistory::query()->create([
            'company_id' => $companyId,
            'lead_id' => $lead->id,
            'from_column_id' => $latestStage?->to_column_id,
            'to_column_id' => $targetColumn->id,
            'moved_by_user_id' => $movedByUserId,
            'move_source' => 'manual',
            'reason' => $reason,
            'moved_at' => now(),
        ]);

        return [
            'lead_id' => $lead->id,
            'kanban_column_id' => $targetColumn->id,
            'moved_by_user_id' => $movedByUserId,
            'movement_type' => 'manual',
            'history_created' => true,
        ];
    }

}
