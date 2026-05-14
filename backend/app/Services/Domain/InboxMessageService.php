<?php

namespace App\Services\Domain;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppProviderInterface;
use Carbon\Carbon;

class InboxMessageService
{
    public function __construct(
        private readonly InboxService $inboxService,
        private readonly WhatsAppProviderInterface $whatsAppProvider,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sendTextMessageForConversation(User $user, int $conversationId, string $body): ?array
    {
        $detail = $this->inboxService->getConversationDetailForUser($user, $conversationId);
        if (!$detail) {
            return null;
        }

        if (!((bool) data_get($detail, 'service_window_open'))) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Service window is closed. Wait for a recent inbound message before sending.',
            ];
        }

        $conversation = Conversation::query()
            ->where('company_id', $user->company_id)
            ->where('id', $conversationId)
            ->first();

        if (!$conversation) {
            return null;
        }

        $phone = (string) data_get($detail, 'lead.phone', '');
        $providerResult = $this->whatsAppProvider->sendTextMessage($phone, $body, [
            'company_id' => (int) $user->company_id,
            'conversation_id' => $conversationId,
            'lead_id' => (int) $conversation->lead_id,
            'user_id' => (int) $user->id,
        ]);

        if (!$providerResult->success) {
            return [
                'success' => false,
                'status' => 422,
                'message' => $providerResult->errorMessage ?: 'Provider failed to send message.',
                'provider' => $providerResult->provider,
                'error_code' => $providerResult->errorCode,
            ];
        }

        $sentAt = Carbon::now();

        $message = Message::create([
            'company_id' => $user->company_id,
            'lead_id' => $conversation->lead_id,
            'conversation_id' => $conversation->id,
            'provider' => $providerResult->provider,
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => $body,
            'sent_at' => $sentAt,
            'external_message_id' => $providerResult->externalMessageId,
            'raw_payload' => $providerResult->rawResponse,
            'metadata' => [
                'provider_context' => 'inbox_send',
            ],
        ]);

        $conversation->update([
            'last_message_at' => $sentAt,
        ]);

        return [
            'success' => true,
            'message' => $message,
        ];
    }
}

