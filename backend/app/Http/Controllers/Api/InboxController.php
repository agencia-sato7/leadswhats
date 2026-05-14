<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\ConversationEventService;
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
}
