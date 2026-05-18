<?php

namespace App\Services\Domain;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSourceHistory;
use App\Models\Message;

class DashboardMetricsService
{
    public function __construct(
        private readonly OperationalChecklistService $operationalChecklistService,
    ) {
    }

    /**
     * @return array{date:string,metrics:array<string,int|float>}
     */
    public function summaryForCompany(int $companyId): array
    {
        $todayStart = now()->startOfDay();

        $newLeadsToday = Lead::where("company_id", $companyId)
            ->whereDate("created_at", today())
            ->where("is_repeat_lead", false)
            ->count();

        $repeatLeadsToday = Lead::where("company_id", $companyId)
            ->whereDate("created_at", today())
            ->where("is_repeat_lead", true)
            ->count();

        $avgResponseSeconds = (int) round((float) Lead::where("company_id", $companyId)
            ->whereNotNull("first_response_seconds")
            ->avg("first_response_seconds"));

        $vacuum24hCount = Lead::where("company_id", $companyId)
            ->whereNotNull("last_outbound_at")
            ->where(function ($query) {
                $query->whereNull("last_inbound_at")
                    ->orWhereColumn("last_inbound_at", "<", "last_outbound_at");
            })
            ->where("last_outbound_at", "<=", now()->subHours(24))
            ->count();

        $rescuesToday = Message::where("company_id", $companyId)
            ->where("direction", "outbound")
            ->where("is_rescue", true)
            ->where("sent_at", ">=", $todayStart)
            ->count();

        $activeConversations = Conversation::where("company_id", $companyId)
            ->where("status", "active")
            ->count();

        $unknownSourceLeads = Lead::where("company_id", $companyId)
            ->where("source", "desconhecido")
            ->count();

        $manualClassificationsToday = LeadSourceHistory::where("company_id", $companyId)
            ->where("change_type", "manual")
            ->where("changed_at", ">=", $todayStart)
            ->count();

        $checklistItems = $this->operationalChecklistService->checklistForCompany($companyId);
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
        $unassignedLeads = Lead::where("company_id", $companyId)
            ->whereNull("owner_user_id")
            ->count();

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
            ],
        ];
    }
}
