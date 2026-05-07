<?php

namespace App\Services\Domain;

use App\Models\KanbanColumn;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;
use Illuminate\Validation\ValidationException;

class KanbanColumnService
{
    /**
     * @param array{name:string,position:int,rule?:string|null} $payload
     */
    public function createColumn(int $companyId, int $pipelineId, array $payload): KanbanColumn
    {
        $pipeline = Pipeline::query()
            ->where('company_id', $companyId)
            ->findOrFail($pipelineId, ['id']);

        return KanbanColumn::query()->create([
            'company_id' => $companyId,
            'pipeline_id' => $pipeline->id,
            'name' => $payload['name'],
            'position' => $payload['position'],
            'rule_prompt' => $payload['rule'] ?? null,
        ]);
    }

    /**
     * @param array{name?:string,position?:int,rule?:string|null} $payload
     */
    public function updateColumn(int $companyId, int $columnId, array $payload): KanbanColumn
    {
        $column = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->findOrFail($columnId);

        $updates = [];

        if (array_key_exists('name', $payload)) {
            $updates['name'] = $payload['name'];
        }

        if (array_key_exists('position', $payload)) {
            $updates['position'] = $payload['position'];
        }

        if (array_key_exists('rule', $payload)) {
            $updates['rule_prompt'] = $payload['rule'];
        }

        if ($updates !== []) {
            $column->fill($updates);
            $column->save();
        }

        return $column->fresh();
    }

    public function deleteColumn(int $companyId, int $columnId): void
    {
        $column = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->findOrFail($columnId);

        $latestStageIdsByLead = LeadStageHistory::query()
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->groupBy('lead_id');

        $hasActiveCards = LeadStageHistory::query()
            ->whereIn('id', $latestStageIdsByLead)
            ->where('to_column_id', $column->id)
            ->exists();

        if ($hasActiveCards) {
            throw ValidationException::withMessages([
                'column' => ['Não é possível remover coluna com cards/leads associados.'],
            ]);
        }

        $column->delete();
    }
}
