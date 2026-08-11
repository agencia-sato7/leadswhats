<?php

namespace App\Services\Domain;

use App\Models\Conversation;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\User;
use App\Services\CompanySettingsService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class InboxService
{
    public function __construct(
        private readonly CompanySettingsService $companySettingsService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listConversationsForUser(User $user, array $filters, ?int $effectiveCompanyId = null): array
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $paginator = $this->baseQueryForUser($user, $filters, $effectiveCompanyId)
            ->select($this->listSelectColumns())
            ->orderByDesc('latest_message.last_message_at')
            ->orderByDesc('conversations.id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $this->normalizeListItems($paginator, $user, $filters),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConversationDetailForUser(
        User $user,
        int $conversationId,
        ?int $effectiveCompanyId = null,
    ): ?array {
        $companyId = $effectiveCompanyId ?? (int) $user->company_id;
        $query = $this->baseQueryForUser($user, [], $companyId)
            ->where('conversations.id', $conversationId)
            ->select($this->detailSelectColumns());

        $conversation = $query->first();
        if (! $conversation) {
            return null;
        }

        $serviceWindow = $this->buildServiceWindow($conversation->last_inbound_at);

        $messages = Message::query()
            ->where('company_id', $companyId)
            ->where('conversation_id', $conversationId)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get([
                'id',
                'direction',
                'body',
                'sent_at',
                'provider',
                'external_message_id',
                'created_at',
            ])
            ->map(static function (Message $message): array {
                return [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'body' => $message->body,
                    'sent_at' => optional($message->sent_at)?->toISOString(),
                    'provider' => $message->provider,
                    'external_message_id' => $message->external_message_id,
                    'created_at' => optional($message->created_at)?->toISOString(),
                ];
            })
            ->values()
            ->all();

        return [
            'conversation_id' => (int) $conversation->conversation_id,
            'status' => $conversation->status,
            'lead' => [
                'lead_id' => (int) $conversation->lead_id,
                'lead_name' => $conversation->lead_name,
                'phone' => $conversation->phone,
                'source' => $conversation->source,
                'current_stage' => $conversation->current_stage,
            ],
            'owner' => [
                'owner_user_id' => $conversation->owner_user_id ? (int) $conversation->owner_user_id : null,
                'owner_name' => $conversation->owner_name,
            ],
            'service_window_open' => $serviceWindow['open'],
            'service_window_expires_at' => $serviceWindow['expires_at'],
            'messages' => $messages,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQueryForUser(User $user, array $filters, ?int $effectiveCompanyId = null): Builder
    {
        $companyId = $effectiveCompanyId ?? (int) $user->company_id;

        $query = Conversation::query()
            ->from('conversations')
            ->where('conversations.company_id', $companyId)
            ->join('leads', function ($join) use ($companyId) {
                $join->on('leads.id', '=', 'conversations.lead_id')
                    ->where('leads.company_id', '=', $companyId);
            })
            ->leftJoinSub($this->latestStageSubquery($companyId), 'latest_stage', function ($join) {
                $join->on('latest_stage.lead_id', '=', 'leads.id');
            })
            ->leftJoin('kanban_columns as current_column', function ($join) use ($companyId) {
                $join->on('current_column.id', '=', 'latest_stage.to_column_id')
                    ->where('current_column.company_id', '=', $companyId);
            })
            ->leftJoinSub($this->latestMessageSubquery($companyId), 'latest_message', function ($join) {
                $join->on('latest_message.conversation_id', '=', 'conversations.id');
            })
            ->leftJoinSub($this->latestInboundSubquery($companyId), 'latest_inbound', function ($join) {
                $join->on('latest_inbound.conversation_id', '=', 'conversations.id');
            })
            ->leftJoin('users as owners', function ($join) use ($companyId) {
                $join->on('owners.id', '=', 'conversations.owner_user_id')
                    ->where('owners.company_id', '=', $companyId);
            });

        $this->applyOwnershipScope($query, $user);
        $this->applyFilters($query, $user, $filters);

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function listSelectColumns(): array
    {
        return [
            'conversations.id as conversation_id',
            'conversations.lead_id as lead_id',
            'leads.name as lead_name',
            'leads.phone_e164 as phone',
            'leads.source as source',
            'current_column.name as current_stage',
            'conversations.owner_user_id as owner_user_id',
            'owners.name as owner_name',
            'latest_message.last_message_body as last_message_body',
            'latest_message.last_message_direction as last_message_direction',
            'latest_message.last_message_at as last_message_at',
            'latest_inbound.last_inbound_at as last_inbound_at',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function detailSelectColumns(): array
    {
        return [
            'conversations.id as conversation_id',
            'conversations.status as status',
            'conversations.lead_id as lead_id',
            'leads.name as lead_name',
            'leads.phone_e164 as phone',
            'leads.source as source',
            'current_column.name as current_stage',
            'conversations.owner_user_id as owner_user_id',
            'owners.name as owner_name',
            'latest_inbound.last_inbound_at as last_inbound_at',
        ];
    }

    private function latestStageSubquery(int $companyId)
    {
        $latestStageIds = LeadStageHistory::query()
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->groupBy('lead_id');

        return LeadStageHistory::query()
            ->from('lead_stage_histories')
            ->joinSub($latestStageIds, 'latest_stage_ids', function ($join) {
                $join->on('lead_stage_histories.id', '=', 'latest_stage_ids.id');
            })
            ->select([
                'lead_stage_histories.lead_id',
                'lead_stage_histories.to_column_id',
            ]);
    }

    private function latestMessageSubquery(int $companyId)
    {
        $latestMessageIds = Message::query()
            ->selectRaw('MAX(id) as id')
            ->where('company_id', $companyId)
            ->groupBy('conversation_id');

        return Message::query()
            ->from('messages')
            ->joinSub($latestMessageIds, 'latest_message_ids', function ($join) {
                $join->on('messages.id', '=', 'latest_message_ids.id');
            })
            ->select([
                'messages.conversation_id',
                'messages.body as last_message_body',
                'messages.direction as last_message_direction',
                'messages.sent_at as last_message_at',
            ]);
    }

    private function latestInboundSubquery(int $companyId)
    {
        return Message::query()
            ->from('messages')
            ->where('messages.company_id', $companyId)
            ->where('messages.direction', 'inbound')
            ->selectRaw('messages.conversation_id, MAX(messages.sent_at) as last_inbound_at')
            ->groupBy('messages.conversation_id');
    }

    private function applyOwnershipScope(Builder $query, User $user): void
    {
        $role = $user->role?->value ?? (string) $user->role;
        if ($role !== 'sdr') {
            return;
        }

        $query->where(function ($scopeQuery) use ($user) {
            $scopeQuery->where('conversations.owner_user_id', $user->id)
                ->orWhere('leads.owner_user_id', $user->id);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, User $user, array $filters): void
    {
        $search = (string) ($filters['search'] ?? '');
        if ($search !== '') {
            $normalizedSearch = preg_replace('/\D+/', '', $search) ?? '';
            $query->where(function ($searchQuery) use ($search, $normalizedSearch) {
                $searchQuery->where('leads.name', 'like', '%'.$search.'%')
                    ->orWhere('leads.phone_e164', 'like', '%'.$search.'%');

                if ($normalizedSearch !== '') {
                    $searchQuery->orWhere('leads.phone_e164', 'like', '%'.$normalizedSearch.'%');
                }
            });
        }

        if (! empty($filters['owner_user_id'])) {
            $query->where('conversations.owner_user_id', (int) $filters['owner_user_id']);
        }

        if (! empty($filters['source'])) {
            $query->where('leads.source', $filters['source']);
        }

        if (! empty($filters['stage_id'])) {
            $query->where('latest_stage.to_column_id', (int) $filters['stage_id']);
        }

        if (array_key_exists('service_window_open', $filters)) {
            $expectedOpen = filter_var($filters['service_window_open'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($expectedOpen === true) {
                $query->whereNotNull('latest_inbound.last_inbound_at')
                    ->where('latest_inbound.last_inbound_at', '>=', now()->subHours(24));
            } elseif ($expectedOpen === false) {
                $query->where(function ($windowQuery) {
                    $windowQuery->whereNull('latest_inbound.last_inbound_at')
                        ->orWhere('latest_inbound.last_inbound_at', '<', now()->subHours(24));
                });
            }
        }

        if (array_key_exists('has_open_task', $filters)) {
            $expectedOpenTask = filter_var($filters['has_open_task'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($expectedOpenTask !== null) {
                $this->applyOpenTaskFilter($query, $user, $expectedOpenTask);
            }
        }
    }

    private function applyOpenTaskFilter(Builder $query, User $user, bool $expectedOpenTask): void
    {
        $thresholdHours = max(1, $this->companySettingsService->rescueThresholdHours((int) $user->company_id));
        $thresholdDate = now()->subHours($thresholdHours);

        if ($expectedOpenTask) {
            $query->where('latest_message.last_message_direction', 'outbound')
                ->where('latest_message.last_message_at', '<=', $thresholdDate);

            return;
        }

        $query->where(function ($taskQuery) use ($thresholdDate) {
            $taskQuery->whereNull('latest_message.last_message_at')
                ->orWhere('latest_message.last_message_direction', '!=', 'outbound')
                ->orWhere('latest_message.last_message_at', '>', $thresholdDate);
        });
    }

    /**
     * @return array{open: bool, expires_at: string|null}
     */
    private function buildServiceWindow(CarbonInterface|string|null $lastInboundAt): array
    {
        if ($lastInboundAt === null) {
            return [
                'open' => false,
                'expires_at' => null,
            ];
        }

        $inbound = $lastInboundAt instanceof CarbonInterface ? $lastInboundAt : Carbon::parse($lastInboundAt);
        $expiresAt = $inbound->copy()->addHours(24);

        return [
            'open' => $inbound->greaterThanOrEqualTo(now()->subHours(24)),
            'expires_at' => $expiresAt->toISOString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeListItems(LengthAwarePaginator $paginator, User $user, array $filters): array
    {
        $hasOpenTaskFilter = filter_var($filters['has_open_task'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $thresholdHours = null;

        if ($hasOpenTaskFilter === null) {
            $thresholdHours = max(1, $this->companySettingsService->rescueThresholdHours((int) $user->company_id));
        }

        return collect($paginator->items())
            ->map(function ($item) use ($hasOpenTaskFilter, $thresholdHours): array {
                $serviceWindow = $this->buildServiceWindow($item->last_inbound_at);

                $hasOpenTask = $hasOpenTaskFilter;
                if ($hasOpenTask === null) {
                    $lastAt = $item->last_message_at;
                    $hasOpenTask = $lastAt !== null
                        && $item->last_message_direction === 'outbound'
                        && Carbon::parse((string) $lastAt)->lessThanOrEqualTo(now()->subHours((int) $thresholdHours));
                }

                return [
                    'conversation_id' => (int) $item->conversation_id,
                    'lead_id' => (int) $item->lead_id,
                    'lead_name' => $item->lead_name,
                    'phone' => $item->phone,
                    'source' => $item->source,
                    'current_stage' => $item->current_stage,
                    'owner_user_id' => $item->owner_user_id ? (int) $item->owner_user_id : null,
                    'owner_name' => $item->owner_name,
                    'last_message_body' => $item->last_message_body,
                    'last_message_direction' => $item->last_message_direction,
                    'last_message_at' => $this->toIsoString($item->last_message_at),
                    'unread_count' => 0,
                    'has_open_task' => (bool) $hasOpenTask,
                    'service_window_open' => $serviceWindow['open'],
                    'service_window_expires_at' => $serviceWindow['expires_at'],
                ];
            })
            ->values()
            ->all();
    }

    private function toIsoString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toISOString();
        }

        return Carbon::parse((string) $value)->toISOString();
    }
}
