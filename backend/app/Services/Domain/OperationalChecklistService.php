<?php

namespace App\Services\Domain;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\User;
use App\Services\CompanySettingsService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class OperationalChecklistService
{
    public function __construct(
        private readonly CompanySettingsService $companySettingsService,
        private readonly BusinessTimeCalculatorService $businessTimeCalculatorService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function checklistForUser(User $user, ?int $effectiveCompanyId = null): array
    {
        $companyId = $effectiveCompanyId ?? (int) $user->company_id;
        $conversations = $this->baseConversationsQuery($companyId)
            // Regra atual para SDR: checklist limitado ao próprio owner da conversa ou lead.
            ->when($user->dataScope() === 'own', function ($query) use ($user) {
                $query->where(function ($scopeQuery) use ($user) {
                    $scopeQuery->where('owner_user_id', $user->id)
                        ->orWhereIn('lead_id', Lead::query()
                            ->where('company_id', $user->company_id)
                            ->where('owner_user_id', $user->id)
                            ->select('id'));
                });
            })
            ->orderByDesc('last_message_at')
            ->get(['id', 'lead_id', 'last_message_at', 'owner_user_id']);

        return $this->buildChecklistItemsForCompany($companyId, $conversations);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function checklistForCompany(int $companyId): array
    {
        $conversations = $this->baseConversationsQuery($companyId)
            ->orderByDesc('last_message_at')
            ->get(['id', 'lead_id', 'last_message_at', 'owner_user_id']);

        return $this->buildChecklistItemsForCompany($companyId, $conversations);
    }

    private function baseConversationsQuery(int $companyId)
    {
        return Conversation::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('last_message_at');
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, array<string, mixed>>
     */
    private function buildChecklistItemsForCompany(int $companyId, $conversations): array
    {
        $rescueThresholdHours = max(1, $this->companySettingsService->rescueThresholdHours($companyId));
        $firstResponseSlaMinutes = max(1, $this->companySettingsService->firstResponseSlaMinutes($companyId));

        if ($conversations->isEmpty()) {
            return [];
        }

        $company = Company::query()->find($companyId, ['id']);
        if (! $company) {
            return [];
        }

        $conversationIds = $conversations->pluck('id')->all();

        $latestMessageIdsByConversation = Message::query()
            ->selectRaw('MAX(id)')
            ->where('company_id', $companyId)
            ->whereIn('conversation_id', $conversationIds)
            ->groupBy('conversation_id');

        $latestMessages = Message::query()
            ->whereIn('id', $latestMessageIdsByConversation)
            ->get(['id', 'conversation_id', 'direction', 'sent_at'])
            ->keyBy('conversation_id');

        $latestInboundAtByConversation = Message::query()
            ->where('company_id', $companyId)
            ->whereIn('conversation_id', $conversationIds)
            ->where('direction', 'inbound')
            ->selectRaw('conversation_id, MAX(sent_at) as latest_inbound_at')
            ->groupBy('conversation_id')
            ->pluck('latest_inbound_at', 'conversation_id');

        $latestOutboundAtByConversation = Message::query()
            ->where('company_id', $companyId)
            ->whereIn('conversation_id', $conversationIds)
            ->where('direction', 'outbound')
            ->selectRaw('conversation_id, MAX(sent_at) as latest_outbound_at')
            ->groupBy('conversation_id')
            ->pluck('latest_outbound_at', 'conversation_id');

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
            if (! $lastMessage) {
                continue;
            }

            $lead = $leadsById->get($conversation->lead_id);
            if (! $lead) {
                continue;
            }

            $hoursSinceLastMessage = (float) $lastMessage->sent_at->diffInRealHours(now());
            $lastDirection = (string) $lastMessage->direction;

            $stage = $latestStages->get($lead->id);
            $currentStageName = null;
            if ($stage && $stage->to_column_id) {
                $currentStageName = $columnNamesById[$stage->to_column_id] ?? null;
            }

            if ($lastDirection === 'outbound' && $hoursSinceLastMessage >= $rescueThresholdHours) {
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

            $latestInboundRaw = $latestInboundAtByConversation->get($conversation->id);
            if (! $latestInboundRaw) {
                continue;
            }

            $latestInboundAt = Carbon::parse((string) $latestInboundRaw);
            $latestOutboundRaw = $latestOutboundAtByConversation->get($conversation->id);
            if ($latestOutboundRaw) {
                $latestOutboundAt = Carbon::parse((string) $latestOutboundRaw);
                if ($latestOutboundAt->gte($latestInboundAt)) {
                    continue;
                }
            }

            $businessSeconds = $this->businessTimeCalculatorService->calculateBusinessSeconds(
                $latestInboundAt,
                now(),
                $company,
            );
            if ($businessSeconds < ($firstResponseSlaMinutes * 60)) {
                continue;
            }

            $items[] = [
                'lead_id' => $lead->id,
                'conversation_id' => $conversation->id,
                'lead_name' => $lead->name,
                'phone' => $lead->phone_e164,
                'source' => $lead->source,
                'current_stage' => $currentStageName,
                'last_message_at' => $latestInboundAt->toISOString(),
                'last_message_direction' => 'inbound',
                'hours_since_last_message' => round($latestInboundAt->diffInRealHours(now()), 2),
                'task_type' => 'waiting_first_response',
                'task_label' => 'Primeiro atendimento atrasado',
                'priority' => 'high',
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) $b['last_message_at'], (string) $a['last_message_at']);
        });

        return $items;
    }
}
