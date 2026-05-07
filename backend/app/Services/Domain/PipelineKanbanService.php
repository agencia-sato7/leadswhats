<?php

namespace App\Services\Domain;

use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PipelineKanbanService
{
    /**
     * @return Collection<int, Pipeline>
     */
    public function listPipelinesForCompany(int $companyId): Collection
    {
        return Pipeline::query()
            ->where("company_id", $companyId)
            ->orderByDesc("is_default")
            ->orderBy("name")
            ->get(["id", "name", "is_default", "created_at", "updated_at"]);
    }

    /**
     * @return array{id:int,name:string,columns:array<int,array<string,mixed>>}|null
     */
    public function kanbanForPipeline(int $companyId, int $pipelineId): ?array
    {
        $pipeline = Pipeline::query()
            ->where("company_id", $companyId)
            ->find($pipelineId, ["id", "name"]);

        if (!$pipeline) {
            return null;
        }

        $columns = KanbanColumn::query()
            ->where("company_id", $companyId)
            ->where("pipeline_id", $pipeline->id)
            ->orderBy("position")
            ->orderBy("id")
            ->get(["id", "name", "position", "rule_prompt"]);

        $columnIds = $columns->pluck("id")->all();

        if (count($columnIds) === 0) {
            return [
                "id" => $pipeline->id,
                "name" => $pipeline->name,
                "columns" => [],
            ];
        }

        $latestStageIdsByLead = LeadStageHistory::query()
            ->selectRaw("MAX(id) as id")
            ->where("company_id", $companyId)
            ->whereIn("to_column_id", $columnIds)
            ->groupBy("lead_id");

        $latestStages = LeadStageHistory::query()
            ->whereIn("id", $latestStageIdsByLead)
            ->get(["lead_id", "to_column_id"]);

        $leadIds = $latestStages->pluck("lead_id")->unique()->values()->all();

        $leads = Lead::query()
            ->where("company_id", $companyId)
            ->whereIn("id", $leadIds)
            ->get(["id", "name", "phone_e164", "source", "is_repeat_lead"]);

        $lastMessageAtByLead = Conversation::query()
            ->where("company_id", $companyId)
            ->whereIn("lead_id", $leadIds)
            ->select("lead_id", DB::raw("MAX(last_message_at) as last_message_at"))
            ->groupBy("lead_id")
            ->pluck("last_message_at", "lead_id");

        $leadsById = $leads->keyBy("id");
        $stagesByColumn = $latestStages->groupBy("to_column_id");

        $columnPayload = [];

        foreach ($columns as $column) {
            $cards = [];
            $stagesForColumn = $stagesByColumn->get($column->id, collect());

            foreach ($stagesForColumn as $stage) {
                $lead = $leadsById->get($stage->lead_id);

                if (!$lead) {
                    continue;
                }

                $cards[] = [
                    "lead_id" => $lead->id,
                    "name" => $lead->name,
                    "phone" => $lead->phone_e164,
                    "source" => $lead->source,
                    "classification" => $lead->is_repeat_lead ? "lead_repetido" : "lead_novo",
                    "last_message_at" => $lastMessageAtByLead[$lead->id] ?? null,
                ];
            }

            usort($cards, static function (array $left, array $right): int {
                $leftTime = $left["last_message_at"];
                $rightTime = $right["last_message_at"];

                if ($leftTime === $rightTime) {
                    return $left["lead_id"] <=> $right["lead_id"];
                }

                if ($leftTime === null) {
                    return 1;
                }

                if ($rightTime === null) {
                    return -1;
                }

                return strcmp((string) $rightTime, (string) $leftTime);
            });

            $columnPayload[] = [
                "id" => $column->id,
                "name" => $column->name,
                "position" => $column->position,
                "rule" => $column->rule_prompt,
                "cards" => $cards,
            ];
        }

        return [
            "id" => $pipeline->id,
            "name" => $pipeline->name,
            "columns" => $columnPayload,
        ];
    }
}
