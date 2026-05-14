<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\ConversationEventService;
use App\Services\Domain\InboxMessageService;
use App\Services\Domain\InboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function index(Request $request, InboxService $inboxService): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'owner_user_id' => ['sometimes', 'integer', 'min:1'],
            'source' => ['sometimes', 'string', 'max:60'],
            'stage_id' => ['sometimes', 'integer', 'min:1'],
            'has_open_task' => ['sometimes', 'in:true,false,1,0'],
            'service_window_open' => ['sometimes', 'in:true,false,1,0'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $inboxService->listConversationsForUser($request->user(), $filters);

        return response()->json($result);
    }

    public function show(
        Request $request,
        int $conversationId,
        InboxService $inboxService,
        ConversationEventService $conversationEventService,
    ): JsonResponse
    {
        $result = $inboxService->getConversationDetailForUser($request->user(), $conversationId);
        if (!$result) {
            return response()->json([
                'message' => 'Conversa não encontrada.',
            ], 404);
        }

        $conversationEventService->registerConversationOpened(
            $request->user(),
            (int) $result['conversation_id'],
            (int) data_get($result, 'lead.lead_id'),
            [
                'source' => 'inbox_api',
            ],
        );

        return response()->json([
            'data' => $result,
        ]);
    }

    public function sendMessage(
        Request $request,
        int $conversationId,
        InboxMessageService $inboxMessageService,
        ConversationEventService $conversationEventService,
    ): JsonResponse {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        if (trim((string) $validated['body']) === '') {
            return response()->json([
                'message' => 'The body field is required.',
                'errors' => [
                    'body' => ['The body field is required.'],
                ],
            ], 422);
        }
        $result = $inboxMessageService->sendTextMessageForConversation(
            $request->user(),
            $conversationId,
            trim((string) $validated['body']),
        );

        if (!$result) {
            return response()->json([
                'message' => 'Conversa não encontrada.',
            ], 404);
        }

        if (!($result['success'] ?? false)) {
            return response()->json([
                'message' => (string) ($result['message'] ?? 'Não foi possível enviar a mensagem.'),
                'provider' => $result['provider'] ?? null,
                'error_code' => $result['error_code'] ?? null,
            ], (int) ($result['status'] ?? 422));
        }

        $message = $result['message'];

        $conversationEventService->registerMessageSent(
            $request->user(),
            (int) $message->conversation_id,
            (int) $message->lead_id,
            [
                'source' => 'inbox_api',
                'provider' => $message->provider,
                'external_message_id' => $message->external_message_id,
            ],
        );

        return response()->json([
            'message' => 'Message sent successfully.',
            'data' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'lead_id' => $message->lead_id,
                'direction' => $message->direction,
                'body' => $message->body,
                'provider' => $message->provider,
                'external_message_id' => $message->external_message_id,
                'sent_at' => optional($message->sent_at)?->toISOString(),
            ],
        ]);
    }
}
