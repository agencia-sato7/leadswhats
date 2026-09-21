<?php

namespace App\Services\Domain;

use App\Models\Conversation;
use App\Models\ConversationQualityScore;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;
use App\Models\User;
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
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default', 'created_at', 'updated_at']);
    }

    /**
     * @return array{id:int,name:string,columns:array<int,array<string,mixed>>}|null
     */
    public function kanbanForPipeline(int $companyId, int $pipelineId, ?User $viewer = null): ?array
    {
        $pipeline = Pipeline::query()
            ->where('company_id', $companyId)
            ->find($pipelineId, ['id', 'name']);

        if (! $pipeline) {
            return null;
        }

        $columns = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->where('pipeline_id', $pipeline->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'name', 'position', 'rule_prompt']);

        $columnIds = $columns->pluck('id')->all();

        if (count($columnIds) === 0) {
            return [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
                'columns' => [],
            ];
        }

        $latestStageIdsByLead = LeadStageHistory::query()
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->whereIn('to_column_id', $columnIds)
            ->groupBy('lead_id');

        $latestStages = LeadStageHistory::query()
            ->whereIn('id', $latestStageIdsByLead)
            ->get(['lead_id', 'to_column_id']);

        $leadIds = $latestStages->pluck('lead_id')->unique()->values()->all();

        $leads = Lead::query()
            ->where('leads.company_id', $companyId)
            ->whereIn('leads.id', $leadIds)
            ->when($viewer?->dataScope() === 'own', function ($query) use ($viewer, $companyId) {
                $query->where(function ($scope) use ($viewer, $companyId) {
                    $scope->where('leads.owner_user_id', $viewer->id)
                        ->orWhereExists(function ($conversation) use ($viewer, $companyId) {
                            $conversation->selectRaw('1')->from('conversations')
                                ->whereColumn('conversations.lead_id', 'leads.id')
                                ->where('conversations.company_id', $companyId)
                                ->where('conversations.owner_user_id', $viewer->id);
                        });
                });
            })
            ->leftJoin('users', 'users.id', '=', 'leads.owner_user_id')
            ->get([
                'leads.id',
                'leads.name',
                'leads.phone_e164',
                'leads.source',
                'leads.is_repeat_lead',
                'users.name as owner_name',
            ]);

        $lastMessageAtByLead = Conversation::query()
            ->where('company_id', $companyId)
            ->whereIn('lead_id', $leadIds)
            ->select('lead_id', DB::raw('MAX(last_message_at) as last_message_at'))
            ->groupBy('lead_id')
            ->pluck('last_message_at', 'lead_id');

        $latestAnalysisIds = ConversationQualityScore::query()
            ->selectRaw('MAX(id)')
            ->where('company_id', $companyId)
            ->whereIn('lead_id', $leadIds)
            ->groupBy('lead_id');

        $latestAnalysesByLead = ConversationQualityScore::query()
            ->whereIn('id', $latestAnalysisIds)
            ->with([
                'recommendedKanbanColumn:id,name',
                'recommendationDecision:id,conversation_quality_score_id,decision,decided_at',
            ])
            ->get([
                'id',
                'conversation_id',
                'lead_id',
                'score',
                'summary',
                'intent',
                'objections',
                'commercial_data',
                'recommended_kanban_column_id',
                'classification_reason',
                'confidence',
                'analyzed_at',
            ])
            ->keyBy('lead_id');

        $leadsById = $leads->keyBy('id');
        $stagesByColumn = $latestStages->groupBy('to_column_id');

        $columnPayload = [];

        foreach ($columns as $column) {
            $cards = [];
            $stagesForColumn = $stagesByColumn->get($column->id, collect());

            foreach ($stagesForColumn as $stage) {
                $lead = $leadsById->get($stage->lead_id);

                if (! $lead) {
                    continue;
                }

                $analysis = $latestAnalysesByLead->get($lead->id);

                $cards[] = [
                    'lead_id' => $lead->id,
                    'name' => $lead->name,
                    'phone' => $lead->phone_e164,
                    'source' => $lead->source,
                    'classification' => $lead->is_repeat_lead ? 'lead_repetido' : 'lead_novo',
                    'last_message_at' => $lastMessageAtByLead[$lead->id] ?? null,
                    'owner_name' => $lead->owner_name,
                    'latest_analysis' => $analysis ? [
                        'id' => $analysis->id,
                        'conversation_id' => $analysis->conversation_id,
                        'score' => $analysis->score,
                        'summary' => $analysis->summary,
                        'intent' => $analysis->intent,
                        'objections' => $analysis->objections ?? [],
                        'commercial_data' => $analysis->commercial_data ?? [],
                        'recommended_kanban_column_id' => $analysis->recommended_kanban_column_id,
                        'recommended_kanban_column_name' => $analysis->recommendedKanbanColumn?->name,
                        'classification_reason' => $analysis->classification_reason,
                        'confidence' => $analysis->confidence,
                        'analyzed_at' => $analysis->analyzed_at?->toIso8601String(),
                        'recommendation_decision' => $analysis->recommendationDecision?->decision,
                    ] : null,
                ];
            }

            usort($cards, static function (array $left, array $right): int {
                $leftTime = $left['last_message_at'];
                $rightTime = $right['last_message_at'];

                if ($leftTime === $rightTime) {
                    return $left['lead_id'] <=> $right['lead_id'];
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
                'id' => $column->id,
                'name' => $column->name,
                'position' => $column->position,
                'rule' => $column->rule_prompt,
                'cards' => $cards,
            ];
        }

        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'columns' => $columnPayload,
        ];
    }
}
