<?php

namespace App\Services\Domain;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ContactDirectoryService
{
    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listForUser(User $user, array $filters): array
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $paginator = $this->baseQueryForUser($user, $filters)
            ->select($this->contactSelectColumns())
            ->orderByDesc('latest_message.last_message_at')
            ->orderByDesc('leads.created_at')
            ->orderBy('leads.id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $this->normalizeItems($paginator),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function exportRowsForUser(User $user, array $filters): array
    {
        $rows = $this->baseQueryForUser($user, $filters)
            ->select($this->contactSelectColumns())
            ->orderByDesc('latest_message.last_message_at')
            ->orderByDesc('leads.created_at')
            ->orderBy('leads.id')
            ->get();

        return $rows
            ->map(static function ($item): array {
                return [
                    'phone' => $item->phone,
                    'name' => $item->name,
                    'source' => $item->source,
                    'classification' => $item->is_repeat_lead ? 'lead_repetido' : 'lead_novo',
                    'current_stage' => $item->current_stage,
                    'created_at' => optional($item->created_at)?->toISOString(),
                    'last_message_at' => optional($item->last_message_at)?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function baseQueryForUser(User $user, array $filters): Builder
    {
        $companyId = (int) $user->company_id;

        $query = Lead::query()
            ->from('leads')
            ->where('leads.company_id', $companyId)
            ->leftJoinSub($this->latestStageSubquery($companyId), 'latest_stage', function ($join) {
                $join->on('latest_stage.lead_id', '=', 'leads.id');
            })
            ->leftJoin('kanban_columns as current_column', function ($join) use ($companyId) {
                $join->on('current_column.id', '=', 'latest_stage.to_column_id')
                    ->where('current_column.company_id', '=', $companyId);
            })
            ->leftJoinSub($this->latestMessageSubquery($companyId), 'latest_message', function ($join) {
                $join->on('latest_message.lead_id', '=', 'leads.id');
            });

        $this->applyOwnershipScope($query, $user);
        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function contactSelectColumns(): array
    {
        return [
            'leads.id as lead_id',
            'leads.name',
            'leads.phone_e164 as phone',
            'leads.source',
            'leads.source_method',
            'leads.is_repeat_lead',
            'current_column.name as current_stage',
            'latest_message.last_message_at',
            'latest_message.last_message_direction',
            'leads.created_at',
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
            ->groupBy('lead_id');

        return Message::query()
            ->from('messages')
            ->joinSub($latestMessageIds, 'latest_message_ids', function ($join) {
                $join->on('messages.id', '=', 'latest_message_ids.id');
            })
            ->select([
                'messages.lead_id',
                'messages.sent_at as last_message_at',
                'messages.direction as last_message_direction',
            ]);
    }

    private function applyOwnershipScope(Builder $query, User $user): void
    {
        $role = $user->role?->value ?? (string) $user->role;
        if ($role !== 'sdr') {
            return;
        }

        $query->where(function ($scopeQuery) use ($user) {
            $scopeQuery->where('leads.owner_user_id', $user->id)
                ->orWhereExists(function ($conversationQuery) use ($user) {
                    $conversationQuery->select(DB::raw('1'))
                        ->from('conversations')
                        ->whereColumn('conversations.lead_id', 'leads.id')
                        ->where('conversations.company_id', $user->company_id)
                        ->where('conversations.owner_user_id', $user->id);
                });
        });
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = (string) ($filters['search'] ?? '');
        if ($search !== '') {
            $normalizedSearch = preg_replace('/\D+/', '', $search) ?? '';
            $query->where(function ($searchQuery) use ($search, $normalizedSearch) {
                $searchQuery->where('leads.name', 'like', '%' . $search . '%')
                    ->orWhere('leads.phone_e164', 'like', '%' . $search . '%');

                if ($normalizedSearch !== '') {
                    $searchQuery->orWhere('leads.phone_e164', 'like', '%' . $normalizedSearch . '%');
                }
            });
        }

        if (!empty($filters['source'])) {
            $query->where('leads.source', $filters['source']);
        }

        if (!empty($filters['classification'])) {
            $classification = (string) $filters['classification'];
            if ($classification === 'lead_novo') {
                $query->where('leads.is_repeat_lead', false);
            } elseif ($classification === 'lead_repetido') {
                $query->where('leads.is_repeat_lead', true);
            }
        }

        $stageId = $filters['stage_id'] ?? $filters['kanban_column_id'] ?? null;
        if ($stageId !== null && $stageId !== '') {
            $query->where('latest_stage.to_column_id', (int) $stageId);
        }

        if (array_key_exists('has_unknown_source', $filters) && $filters['has_unknown_source'] !== null) {
            $hasUnknown = filter_var($filters['has_unknown_source'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($hasUnknown === true) {
                $query->where('leads.source', 'desconhecido');
            } elseif ($hasUnknown === false) {
                $query->where('leads.source', '!=', 'desconhecido');
            }
        }

        if (!empty($filters['created_from'])) {
            $query->whereDate('leads.created_at', '>=', $filters['created_from']);
        }

        if (!empty($filters['created_to'])) {
            $query->whereDate('leads.created_at', '<=', $filters['created_to']);
        }

        if (!empty($filters['last_message_from'])) {
            $query->where('latest_message.last_message_at', '>=', $filters['last_message_from']);
        }

        if (!empty($filters['last_message_to'])) {
            $query->where('latest_message.last_message_at', '<=', $filters['last_message_to']);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())
            ->map(static function ($item): array {
                return [
                    'lead_id' => (int) $item->lead_id,
                    'name' => $item->name,
                    'phone' => $item->phone,
                    'source' => $item->source,
                    'source_method' => $item->source_method,
                    'classification' => $item->is_repeat_lead ? 'lead_repetido' : 'lead_novo',
                    'current_stage' => $item->current_stage,
                    'last_message_at' => optional($item->last_message_at)?->toISOString(),
                    'last_message_direction' => $item->last_message_direction,
                    'created_at' => optional($item->created_at)?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }
}
