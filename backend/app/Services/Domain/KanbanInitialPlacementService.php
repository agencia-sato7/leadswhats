<?php

namespace App\Services\Domain;

use App\Models\KanbanColumn;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;

class KanbanInitialPlacementService
{
    public function placeLeadInInitialColumnIfMissing(int $companyId, int $leadId): bool
    {
        $hasStage = LeadStageHistory::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $leadId)
            ->exists();

        if ($hasStage) {
            return false;
        }

        $defaultPipeline = Pipeline::query()
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->orderBy('id')
            ->first(['id']);

        if (!$defaultPipeline) {
            return false;
        }

        $initialColumn = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->where('pipeline_id', $defaultPipeline->id)
            ->orderBy('position')
            ->orderBy('id')
            ->first(['id']);

        if (!$initialColumn) {
            return false;
        }

        LeadStageHistory::query()->create([
            'company_id' => $companyId,
            'lead_id' => $leadId,
            'from_column_id' => null,
            'to_column_id' => $initialColumn->id,
            'moved_by_user_id' => null,
            'move_source' => 'system',
            'reason' => 'Lead criado via webhook',
            'moved_at' => now(),
        ]);

        return true;
    }
}
