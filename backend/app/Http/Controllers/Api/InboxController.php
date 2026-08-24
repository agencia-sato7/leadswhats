<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\ConversationEventService;
use App\Services\Domain\InboxConversationTimelineService;
use App\Services\Domain\InboxService;
use App\Services\Domain\InboxMessageService;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use App\Services\EffectiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function index(
        Request $request,
        InboxService $inboxService,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
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

        $result = $inboxService->listConversationsForUser(
            $request->user(),
            $filters,
            $tenantContext->companyId($request),
        );

        return response()->json($result);
    }

    public function show(
        Request $request,
        int $conversationId,
        InboxService $inboxService,
        ConversationEventService $conversationEventService,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $result = $inboxService->getConversationDetailForUser(
            $request->user(),
            $conversationId,
            $tenantContext->companyId($request),
        );
        if (! $result) {
            return response()->json([
                'message' => 'Conversa não encontrada.',
            ], 404);
        }

        if (! $tenantContext->isPlatformView($request)) {
            $conversationEventService->registerConversationOpened(
                $request->user(),
                (int) $result['conversation_id'],
                (int) data_get($result, 'lead.lead_id'),
                [
                    'source' => 'inbox_api',
                ],
            );
        }

        return response()->json([
            'data' => $result,
        ]);
    }

    public function events(
        Request $request,
        int $conversationId,
        InboxConversationTimelineService $timelineService,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $timeline = $timelineService->listEventsForConversation(
            $request->user(),
            $conversationId,
            $tenantContext->companyId($request),
        );
        if (! $timeline) {
            return response()->json([
                'message' => 'Conversa não encontrada.',
            ], 404);
        }

        return response()->json($timeline);
    }

    public function sendMessage(Request $request, int $conversationId, InboxMessageService $service): JsonResponse
    {
        $request->validate(['body' => ['nullable', 'string', 'max:4096'], 'file' => ['nullable', 'file', 'max:102400']]);
        try {
            $message = $service->send($request->user(), $conversationId, $request->input('body'), $request->file('file'));
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], in_array($exception->getCode(), [404, 422], true) ? $exception->getCode() : 422);
        }
        return response()->json(['data' => $message], 201);
    }

    public function attachment(Request $request, int $attachmentId, InboxService $inboxService, EffectiveTenantContext $tenantContext)
    {
        $attachment = MessageAttachment::query()->with('message')->find($attachmentId);
        if (! $attachment || ! $attachment->message || ! $inboxService->getConversationDetailForUser($request->user(), $attachment->message->conversation_id, $tenantContext->companyId($request))) abort(404);
        if (! Storage::disk($attachment->disk)->exists($attachment->path)) abort(404);
        $disposition = $attachment->type === 'document' ? 'attachment' : 'inline';
        return response()->file(Storage::disk($attachment->disk)->path($attachment->path), [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => $disposition.'; filename="'.addslashes($attachment->original_name ?: 'arquivo').'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
