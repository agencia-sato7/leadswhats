<?php

namespace App\Services\Domain;

use App\Models\ConversationEvent;
use App\Models\User;

class ConversationEventService
{
    private const EVENT_CONVERSATION_OPENED = 'conversation_opened';
    private const EVENT_MESSAGE_SENT = 'message_sent';
    private const DEDUP_SECONDS = 10;

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function registerConversationOpened(
        User $user,
        int $conversationId,
        int $leadId,
        ?array $metadata = null,
    ): void {
        $recentDuplicate = ConversationEvent::query()
            ->where('company_id', $user->company_id)
            ->where('conversation_id', $conversationId)
            ->where('lead_id', $leadId)
            ->where('user_id', $user->id)
            ->where('event_type', self::EVENT_CONVERSATION_OPENED)
            ->where('created_at', '>=', now()->subSeconds(self::DEDUP_SECONDS))
            ->exists();

        if ($recentDuplicate) {
            return;
        }

        ConversationEvent::create([
            'company_id' => $user->company_id,
            'conversation_id' => $conversationId,
            'lead_id' => $leadId,
            'user_id' => $user->id,
            'event_type' => self::EVENT_CONVERSATION_OPENED,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function registerMessageSent(
        User $user,
        int $conversationId,
        int $leadId,
        ?array $metadata = null,
    ): void {
        ConversationEvent::create([
            'company_id' => $user->company_id,
            'conversation_id' => $conversationId,
            'lead_id' => $leadId,
            'user_id' => $user->id,
            'event_type' => self::EVENT_MESSAGE_SENT,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
