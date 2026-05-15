<?php

namespace App\Services\Domain;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadOwnerService
{
    public function __construct(
        private readonly ConversationEventService $conversationEventService,
    ) {
    }

    /**
     * @return array<string, int|string|null>
     */
    public function updateLeadOwner(User $actor, int $leadId, ?int $ownerUserId, string $reason): array
    {
        $owner = $this->resolveOwner($actor, $ownerUserId);

        return DB::transaction(function () use ($actor, $leadId, $owner, $reason): array {
            $lead = Lead::query()
                ->where('company_id', $actor->company_id)
                ->find($leadId);

            if (!$lead) {
                throw (new ModelNotFoundException())->setModel(Lead::class, [$leadId]);
            }

            $previousOwnerId = $lead->owner_user_id ? (int) $lead->owner_user_id : null;
            $nextOwnerId = $owner?->id;
            $previousOwnerName = $this->ownerName($actor, $previousOwnerId);
            $nextOwnerName = $owner?->name;

            $lead->owner_user_id = $nextOwnerId;
            $lead->save();

            $activeConversations = $this->findActiveConversations((int) $lead->company_id, (int) $lead->id);
            foreach ($activeConversations as $activeConversation) {
                $activeConversation->owner_user_id = $nextOwnerId;
                $activeConversation->save();
            }

            $eventType = $this->resolveEventType($previousOwnerId, $nextOwnerId);
            $eventConversation = $activeConversations->first();
            if ($eventConversation && $eventType) {
                $this->conversationEventService->registerOwnershipChanged(
                    $actor,
                    (int) $eventConversation->id,
                    (int) $lead->id,
                    $eventType,
                    [
                        'previous_owner_user_id' => $previousOwnerId,
                        'previous_owner_name' => $previousOwnerName,
                        'new_owner_user_id' => $nextOwnerId,
                        'new_owner_name' => $nextOwnerName,
                        'reason' => $reason,
                    ],
                );
            }

            return [
                'lead_id' => (int) $lead->id,
                'owner_user_id' => $nextOwnerId,
                'owner_name' => $nextOwnerName,
                'updated_by_user_id' => (int) $actor->id,
            ];
        });
    }

    private function resolveOwner(User $actor, ?int $ownerUserId): ?User
    {
        if ($ownerUserId === null) {
            return null;
        }

        $owner = User::query()
            ->where('company_id', $actor->company_id)
            ->where('id', $ownerUserId)
            ->whereIn('role', ['admin', 'gestor', 'sdr'])
            ->first();

        if ($owner) {
            return $owner;
        }

        throw ValidationException::withMessages([
            'owner_user_id' => ['owner_user_id inválido para a empresa autenticada.'],
        ]);
    }

    private function ownerName(User $actor, ?int $ownerUserId): ?string
    {
        if ($ownerUserId === null) {
            return null;
        }

        return User::query()
            ->where('company_id', $actor->company_id)
            ->where('id', $ownerUserId)
            ->value('name');
    }

    /**
     * @return Collection<int, Conversation>
     */
    private function findActiveConversations(int $companyId, int $leadId): Collection
    {
        return Conversation::query()
            ->where('company_id', $companyId)
            ->where('lead_id', $leadId)
            ->where('status', 'active')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    private function resolveEventType(?int $previousOwnerUserId, ?int $newOwnerUserId): ?string
    {
        if ($previousOwnerUserId === null && $newOwnerUserId !== null) {
            return 'owner_assigned';
        }

        if ($previousOwnerUserId !== null && $newOwnerUserId === null) {
            return 'owner_removed';
        }

        if ($previousOwnerUserId !== null && $newOwnerUserId !== null && $previousOwnerUserId !== $newOwnerUserId) {
            return 'owner_changed';
        }

        return null;
    }
}
