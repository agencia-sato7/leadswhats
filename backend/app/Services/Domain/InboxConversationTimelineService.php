<?php

namespace App\Services\Domain;

use App\Models\ConversationEvent;
use App\Models\LeadStageHistory;
use App\Models\User;
use Illuminate\Support\Collection;

class InboxConversationTimelineService
{
    public function __construct(
        private readonly InboxService $inboxService,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function listEventsForConversation(User $user, int $conversationId): ?array
    {
        $detail = $this->inboxService->getConversationDetailForUser($user, $conversationId);
        if (!$detail) {
            return null;
        }

        $leadId = (int) data_get($detail, 'lead.lead_id');

        $events = $this->conversationEvents($user, $conversationId)
            ->merge($this->stageEvents($user, $leadId))
            ->all();

        usort($events, static function (array $a, array $b): int {
            $timeCompare = strcmp((string) $a['occurred_at'], (string) $b['occurred_at']);
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            $typeCompare = strcmp((string) $a['event_type'], (string) $b['event_type']);
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return ((int) $a['event_id']) <=> ((int) $b['event_id']);
        });

        return [
            'data' => $events,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function conversationEvents(User $user, int $conversationId): Collection
    {
        return ConversationEvent::query()
            ->from('conversation_events')
            ->leftJoin('users', function ($join) use ($user) {
                $join->on('users.id', '=', 'conversation_events.user_id')
                    ->where('users.company_id', '=', $user->company_id);
            })
            ->where('conversation_events.company_id', $user->company_id)
            ->where('conversation_events.conversation_id', $conversationId)
            ->whereIn('conversation_events.event_type', [
                'conversation_opened',
                'message_sent',
                'owner_assigned',
                'owner_changed',
                'owner_removed',
            ])
            ->orderBy('conversation_events.created_at')
            ->orderBy('conversation_events.id')
            ->get([
                'conversation_events.id as event_id',
                'conversation_events.event_type',
                'conversation_events.user_id',
                'users.name as user_name',
                'conversation_events.created_at as occurred_at',
                'conversation_events.metadata',
            ])
            ->map(static function ($event): array {
                return [
                    'event_id' => (int) $event->event_id,
                    'event_type' => (string) $event->event_type,
                    'user_id' => $event->user_id ? (int) $event->user_id : null,
                    'user_name' => $event->user_name,
                    'occurred_at' => optional($event->occurred_at)?->toISOString(),
                    'metadata' => is_array($event->metadata) ? $event->metadata : null,
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function stageEvents(User $user, int $leadId): Collection
    {
        return LeadStageHistory::query()
            ->from('lead_stage_histories')
            ->leftJoin('users', function ($join) use ($user) {
                $join->on('users.id', '=', 'lead_stage_histories.moved_by_user_id')
                    ->where('users.company_id', '=', $user->company_id);
            })
            ->leftJoin('kanban_columns as from_columns', function ($join) use ($user) {
                $join->on('from_columns.id', '=', 'lead_stage_histories.from_column_id')
                    ->where('from_columns.company_id', '=', $user->company_id);
            })
            ->leftJoin('kanban_columns as to_columns', function ($join) use ($user) {
                $join->on('to_columns.id', '=', 'lead_stage_histories.to_column_id')
                    ->where('to_columns.company_id', '=', $user->company_id);
            })
            ->where('lead_stage_histories.company_id', $user->company_id)
            ->where('lead_stage_histories.lead_id', $leadId)
            ->orderBy('lead_stage_histories.moved_at')
            ->orderBy('lead_stage_histories.id')
            ->get([
                'lead_stage_histories.id as event_id',
                'lead_stage_histories.moved_by_user_id as user_id',
                'users.name as user_name',
                'lead_stage_histories.moved_at as occurred_at',
                'lead_stage_histories.from_column_id',
                'from_columns.name as from_column_name',
                'lead_stage_histories.to_column_id',
                'to_columns.name as to_column_name',
                'lead_stage_histories.move_source',
                'lead_stage_histories.reason',
            ])
            ->map(static function ($event): array {
                return [
                    'event_id' => (int) $event->event_id,
                    'event_type' => 'stage_changed',
                    'user_id' => $event->user_id ? (int) $event->user_id : null,
                    'user_name' => $event->user_name,
                    'occurred_at' => optional($event->occurred_at)?->toISOString(),
                    'metadata' => [
                        'from_column_id' => $event->from_column_id ? (int) $event->from_column_id : null,
                        'from_column_name' => $event->from_column_name,
                        'to_column_id' => $event->to_column_id ? (int) $event->to_column_id : null,
                        'to_column_name' => $event->to_column_name,
                        'move_source' => $event->move_source,
                        'reason' => $event->reason,
                    ],
                ];
            });
    }
}
