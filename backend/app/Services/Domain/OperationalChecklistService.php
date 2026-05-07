<?php

namespace App\Services\Domain;

use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\User;
use App\Services\CompanySettingsService;

class OperationalChecklistService
{
    public function __construct(
        private readonly CompanySettingsService $companySettingsService,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function checklistForUser(User $user): array
    {
        $companyId = (int) $user->company_id;
        $rescueThresholdHours = max(1, $this->companySettingsService->rescueThresholdHours($companyId));

        $conversationsQuery = Conversation::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('last_message_at');

        // Regra atual para SDR: checklist limitado ao próprio owner da conversa ou lead.
        if (($user->role?->value ?? (string) $user->role) === 'sdr') {
            $conversationsQuery->where(function ($query) use ($user) {
                $query->where('owner_user_id', $user->id)
                    ->orWhereIn('lead_id', Lead::query()
                        ->where('company_id', $user->company_id)
                        ->where('owner_user_id', $user->id)
                        ->select('id'));
            });
        }

        $conversations = $conversationsQuery
            ->orderByDesc('last_message_at')
            ->get(['id', 'lead_id', 'last_message_at', 'owner_user_id']);

        if ($conversations->isEmpty()) {
            return [];
        }

        $latestMessageIdsByConversation = Message::query()
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->whereIn('conversation_id', $conversations->pluck('id')->all())
            ->groupBy('conversation_id');

        $latestMessages = Message::query()
            ->whereIn('id', $latestMessageIdsByConversation)
            ->get(['id', 'conversation_id', 'direction', 'sent_at'])
            ->keyBy('conversation_id');

        $leadIds = $conversations->pluck('lead_id')->unique()->values()->all();

        $leadsById = Lead::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $leadIds)
            ->get(['id', 'name', 'phone_e164', 'source'])
            ->keyBy('id');

        $latestStageIdsByLead = LeadStageHistory::query()
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->whereIn('lead_id', $leadIds)
            ->groupBy('lead_id');

        $latestStages = LeadStageHistory::query()
            ->whereIn('id', $latestStageIdsByLead)
            ->get(['lead_id', 'to_column_id'])
            ->keyBy('lead_id');

        $columnNamesById = KanbanColumn::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $latestStages->pluck('to_column_id')->filter()->values()->all())
            ->pluck('name', 'id');

        $items = [];

        foreach ($conversations as $conversation) {
            $lastMessage = $latestMessages->get($conversation->id);
            if (!$lastMessage) {
                continue;
            }

            $lead = $leadsById->get($conversation->lead_id);
            if (!$lead) {
                continue;
            }

            $hoursSinceLastMessage = (float) $lastMessage->sent_at->diffInRealHours(now());
            $lastDirection = (string) $lastMessage->direction;

            // Escopo inicial: apenas vacuum_follow_up. waiting_first_response fica para etapa futura.
            if ($lastDirection !== 'outbound' || $hoursSinceLastMessage < $rescueThresholdHours) {
                continue;
            }

            $stage = $latestStages->get($lead->id);
            $currentStageName = null;
            if ($stage && $stage->to_column_id) {
                $currentStageName = $columnNamesById[$stage->to_column_id] ?? null;
            }

            $items[] = [
                'lead_id' => $lead->id,
                'conversation_id' => $conversation->id,
                'lead_name' => $lead->name,
                'phone' => $lead->phone_e164,
                'source' => $lead->source,
                'current_stage' => $currentStageName,
                'last_message_at' => $lastMessage->sent_at?->toISOString(),
                'last_message_direction' => $lastDirection,
                'hours_since_last_message' => round($hoursSinceLastMessage, 2),
                'task_type' => 'vacuum_follow_up',
                'task_label' => 'Lead em vácuo - follow-up pendente',
                'priority' => $hoursSinceLastMessage >= ($rescueThresholdHours * 2) ? 'high' : 'medium',
            ];
        }

        return $items;
    }
}
