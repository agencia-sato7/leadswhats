<?php

namespace App\Services\Domain;

use App\Models\Conversation;
use App\Models\ConversationQualityScore;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadSourceHistory;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardMetricsService
{
    public function __construct(
        private readonly OperationalChecklistService $operationalChecklistService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForCompany(int $companyId): array
    {
        return $this->buildSummary($companyId, null);
    }

    /**
     * Regra da Privacidade: um SDR só pode ver o próprio desempenho, nunca o da equipe inteira.
     *
     * @return array<string, mixed>
     */
    public function summaryForUser(User $user): array
    {
        return $this->buildSummary((int) $user->company_id, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(int $companyId, ?User $scopeUser): array
    {
        $todayStart = now()->startOfDay();

        $newLeadsToday = Lead::where("company_id", $companyId)
            ->whereDate("created_at", today())
            ->where("is_repeat_lead", false)
            ->when($scopeUser, fn ($query) => $query->where("owner_user_id", $scopeUser->id))
            ->count();

        $repeatLeadsToday = Lead::where("company_id", $companyId)
            ->whereDate("created_at", today())
            ->where("is_repeat_lead", true)
            ->when($scopeUser, fn ($query) => $query->where("owner_user_id", $scopeUser->id))
            ->count();

        $avgResponseSeconds = (int) round((float) Lead::where("company_id", $companyId)
            ->whereNotNull("first_response_seconds")
            ->when($scopeUser, fn ($query) => $query->where("owner_user_id", $scopeUser->id))
            ->avg("first_response_seconds"));

        $vacuum24hCount = Lead::where("company_id", $companyId)
            ->whereNotNull("last_outbound_at")
            ->where(function ($query) {
                $query->whereNull("last_inbound_at")
                    ->orWhereColumn("last_inbound_at", "<", "last_outbound_at");
            })
            ->where("last_outbound_at", "<=", now()->subHours(24))
            ->when($scopeUser, fn ($query) => $query->where("owner_user_id", $scopeUser->id))
            ->count();

        $rescuesToday = Message::where("company_id", $companyId)
            ->where("direction", "outbound")
            ->where("is_rescue", true)
            ->where("sent_at", ">=", $todayStart)
            ->when($scopeUser, function ($query) use ($companyId, $scopeUser) {
                $query->where(function ($ownerQuery) use ($companyId, $scopeUser) {
                    $ownerQuery->whereIn("lead_id", Lead::where("company_id", $companyId)
                        ->where("owner_user_id", $scopeUser->id)
                        ->select("id"))
                        ->orWhereIn("conversation_id", Conversation::where("company_id", $companyId)
                            ->where("owner_user_id", $scopeUser->id)
                            ->select("id"));
                });
            })
            ->count();

        $activeConversations = Conversation::where("company_id", $companyId)
            ->where("status", "active")
            ->when($scopeUser, fn ($query) => $query->where("owner_user_id", $scopeUser->id))
            ->count();

        $unknownSourceLeads = Lead::where("company_id", $companyId)
            ->where("source", "desconhecido")
            ->when($scopeUser, fn ($query) => $query->where("owner_user_id", $scopeUser->id))
            ->count();

        $manualClassificationsToday = LeadSourceHistory::where("company_id", $companyId)
            ->where("change_type", "manual")
            ->where("changed_at", ">=", $todayStart)
            ->when($scopeUser, fn ($query) => $query->whereIn("lead_id", Lead::where("company_id", $companyId)
                ->where("owner_user_id", $scopeUser->id)
                ->select("id")))
            ->count();

        $checklistItems = $scopeUser
            ? $this->operationalChecklistService->checklistForUser($scopeUser)
            : $this->operationalChecklistService->checklistForCompany($companyId);
        $openTasks = count($checklistItems);
        $vacuumFollowUpTasks = count(array_filter(
            $checklistItems,
            static fn (array $item): bool => ($item["task_type"] ?? null) === "vacuum_follow_up"
        ));
        $waitingFirstResponseTasks = count(array_filter(
            $checklistItems,
            static fn (array $item): bool => ($item["task_type"] ?? null) === "waiting_first_response"
        ));
        $overdueFollowUpTasks = $vacuumFollowUpTasks;
        $oldestPendingTaskHours = $openTasks > 0
            ? (float) max(array_map(
                static fn (array $item): float => (float) ($item["hours_since_last_message"] ?? 0),
                $checklistItems
            ))
            : 0.0;
        // Um lead sem dono nunca é "do SDR" — 0 é a resposta correta e evita vazar a contagem da empresa.
        $unassignedLeads = $scopeUser
            ? 0
            : Lead::where("company_id", $companyId)
                ->whereNull("owner_user_id")
                ->count();

        // 1. Efetividade de conversas (Sucesso vs Perdidas)
        $successfulConversationsCount = DB::table('conversations as c')
            ->where('c.company_id', $companyId)
            ->when($scopeUser, fn ($query) => $query->where('c.owner_user_id', $scopeUser->id))
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('messages as m1')
                    ->whereColumn('m1.conversation_id', 'c.id')
                    ->where('m1.direction', 'inbound')
                    ->whereExists(function ($query2) {
                        $query2->select(DB::raw(1))
                            ->from('messages as m2')
                            ->whereColumn('m2.conversation_id', 'm1.conversation_id')
                            ->where('m2.direction', 'outbound')
                            ->whereColumn('m2.sent_at', '>', 'm1.sent_at')
                            ->whereExists(function ($query3) {
                                $query3->select(DB::raw(1))
                                    ->from('messages as m3')
                                    ->whereColumn('m3.conversation_id', 'm2.conversation_id')
                                    ->where('m3.direction', 'inbound')
                                    ->whereColumn('m3.sent_at', '>', 'm2.sent_at');
                            });
                    });
            })
            ->count();

        $lostConversationsCount = DB::table('conversations as c')
            ->where('c.company_id', $companyId)
            ->when($scopeUser, fn ($query) => $query->where('c.owner_user_id', $scopeUser->id))
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('messages as m')
                    ->whereColumn('m.conversation_id', 'c.id')
                    ->where('m.direction', 'outbound');
            })
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('messages as ml')
                    ->whereColumn('ml.conversation_id', 'c.id')
                    ->whereRaw('ml.id = (SELECT MAX(id) FROM messages WHERE conversation_id = c.id)')
                    ->where('ml.direction', 'outbound')
                    ->where('ml.sent_at', '<=', now()->subHours(24));
            })
            ->count();

        $effectivenessPercentage = ($successfulConversationsCount + $lostConversationsCount) > 0
            ? (int) round(($successfulConversationsCount / ($successfulConversationsCount + $lostConversationsCount)) * 100)
            : 0;

        $latestScoreIds = ConversationQualityScore::query()
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->when($scopeUser, fn ($query) => $query->whereIn('lead_id', Lead::query()
                ->where('company_id', $companyId)
                ->where('owner_user_id', $scopeUser->id)
                ->select('id')))
            ->groupBy('conversation_id');

        $latestScores = ConversationQualityScore::query()
            ->whereIn('id', (clone $latestScoreIds));
        $averageConversationQuality = (clone $latestScores)->avg('score');
        $lowQualityConversations = (clone $latestScores)->where('score', '<', 60)->count();

        // A etapa atual sempre é o último histórico persistido do lead.
        $latestStageIds = DB::table('lead_stage_histories')
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->groupBy('lead_id');

        $latestStages = DB::table('lead_stage_histories as lsh')
            ->select('lsh.lead_id', 'lsh.to_column_id')
            ->joinSub($latestStageIds, 'latest', 'lsh.id', '=', 'latest.id');

        $aiStageMismatchOpportunities = DB::table('conversation_quality_scores as cqs')
            ->joinSub((clone $latestScoreIds), 'latest_score', 'cqs.id', '=', 'latest_score.id')
            ->joinSub((clone $latestStages), 'latest_stage', 'cqs.lead_id', '=', 'latest_stage.lead_id')
            ->join('leads as recommendation_lead', 'recommendation_lead.id', '=', 'cqs.lead_id')
            ->leftJoin('conversation_analysis_decisions as cad', 'cad.conversation_quality_score_id', '=', 'cqs.id')
            ->where('cqs.company_id', $companyId)
            ->whereNotNull('cqs.recommended_kanban_column_id')
            ->whereColumn('cqs.recommended_kanban_column_id', '<>', 'latest_stage.to_column_id')
            ->whereNull('cad.id')
            ->when($scopeUser, fn ($query) => $query->where('recommendation_lead.owner_user_id', $scopeUser->id))
            ->count();

        $pipeline = Pipeline::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first(['id']);
        $funnel = [];

        if ($pipeline) {
            $columns = KanbanColumn::query()
                ->where('company_id', $companyId)
                ->where('pipeline_id', $pipeline->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'name', 'position']);
            $columnIds = $columns->pluck('id')->all();
            $stageCounts = count($columnIds) > 0
                ? DB::query()
                    ->fromSub((clone $latestStages), 'current_stage')
                    ->join('leads as stage_lead', 'stage_lead.id', '=', 'current_stage.lead_id')
                    ->where('stage_lead.company_id', $companyId)
                    ->whereIn('current_stage.to_column_id', $columnIds)
                    ->when($scopeUser, fn ($query) => $query->where('stage_lead.owner_user_id', $scopeUser->id))
                    ->selectRaw('current_stage.to_column_id, COUNT(*) as total')
                    ->groupBy('current_stage.to_column_id')
                    ->pluck('total', 'current_stage.to_column_id')
                : collect();

            $funnel = $columns->map(static fn (KanbanColumn $column): array => [
                'stage_name' => $column->name,
                'position' => (int) $column->position,
                'count' => (int) ($stageCounts[$column->id] ?? 0),
            ])->all();
        }

        $ownerIds = Lead::query()
            ->where('company_id', $companyId)
            ->whereNotNull('owner_user_id')
            ->when($scopeUser, fn ($query) => $query->where('owner_user_id', $scopeUser->id))
            ->distinct()
            ->pluck('owner_user_id');
        $activeOpportunitiesByOwner = Conversation::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereIn('owner_user_id', $ownerIds)
            ->selectRaw('owner_user_id, COUNT(*) as total')
            ->groupBy('owner_user_id')
            ->pluck('total', 'owner_user_id');
        $averageResponseByOwner = Lead::query()
            ->where('company_id', $companyId)
            ->whereIn('owner_user_id', $ownerIds)
            ->whereNotNull('first_response_seconds')
            ->selectRaw('owner_user_id, AVG(first_response_seconds) as average_seconds')
            ->groupBy('owner_user_id')
            ->pluck('average_seconds', 'owner_user_id');
        $averageQualityByOwner = DB::table('conversation_quality_scores as team_score')
            ->joinSub((clone $latestScoreIds), 'latest_team_score', 'team_score.id', '=', 'latest_team_score.id')
            ->join('leads as team_lead', 'team_lead.id', '=', 'team_score.lead_id')
            ->where('team_score.company_id', $companyId)
            ->whereIn('team_lead.owner_user_id', $ownerIds)
            ->selectRaw('team_lead.owner_user_id, AVG(team_score.score) as average_score')
            ->groupBy('team_lead.owner_user_id')
            ->pluck('average_score', 'team_lead.owner_user_id');
        $teamPerformance = User::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ownerIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $owner): array => [
                'name' => $owner->name,
                'active_opportunities' => (int) ($activeOpportunitiesByOwner[$owner->id] ?? 0),
                'average_score' => isset($averageQualityByOwner[$owner->id])
                    ? round((float) $averageQualityByOwner[$owner->id], 1)
                    : null,
                'avg_first_response_seconds' => isset($averageResponseByOwner[$owner->id])
                    ? (int) round((float) $averageResponseByOwner[$owner->id])
                    : null,
            ])
            ->all();

        // Mantido por compatibilidade; a Visão Geral não exibe mais marketing por origem.

        $funnelBySource = DB::table('leads as l')
            ->joinSub($latestStages, 'latest_stage', 'l.id', '=', 'latest_stage.lead_id')
            ->join('kanban_columns as kc', 'kc.id', '=', 'latest_stage.to_column_id')
            ->select('l.source', 'kc.name as stage_name', DB::raw('count(*) as count'))
            ->where('l.company_id', $companyId)
            ->when($scopeUser, fn ($query) => $query->where('l.owner_user_id', $scopeUser->id))
            ->groupBy('l.source', 'kc.name')
            ->get()
            ->map(function ($row) {
                return [
                    'source' => (string) $row->source,
                    'stage_name' => (string) $row->stage_name,
                    'count' => (int) $row->count,
                ];
            })
            ->all();

        return [
            "date" => today()->toDateString(),
            "metrics" => [
                "new_leads_today" => $newLeadsToday,
                "repeat_leads_today" => $repeatLeadsToday,
                "avg_first_response_seconds" => $avgResponseSeconds,
                "vacuum_24h_open" => $vacuum24hCount,
                "rescues_today" => $rescuesToday,
                "active_conversations" => $activeConversations,
                "unknown_source_leads" => $unknownSourceLeads,
                "manual_classifications_today" => $manualClassificationsToday,
                "open_tasks" => $openTasks,
                "vacuum_follow_up_tasks" => $vacuumFollowUpTasks,
                "waiting_first_response_tasks" => $waitingFirstResponseTasks,
                "overdue_follow_up_tasks" => $overdueFollowUpTasks,
                "unassigned_leads" => $unassignedLeads,
                "oldest_pending_task_hours" => round($oldestPendingTaskHours, 2),
                "successful_conversations_today" => $successfulConversationsCount,
                "lost_conversations_today" => $lostConversationsCount,
                "effectiveness_percentage" => $effectivenessPercentage,
                'average_conversation_quality' => $averageConversationQuality !== null
                    ? round((float) $averageConversationQuality, 1)
                    : null,
                'low_quality_conversations' => $lowQualityConversations,
                'ai_stage_mismatch_opportunities' => $aiStageMismatchOpportunities,
            ],
            'funnel' => $funnel,
            'team_performance' => $teamPerformance,
            "funnel_by_source" => $funnelBySource,
        ];
    }
}
