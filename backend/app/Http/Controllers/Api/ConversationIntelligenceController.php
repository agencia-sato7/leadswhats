<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConversationAnalyzerUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\Domain\ConversationIntelligenceService;
use App\Services\EffectiveTenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ConversationIntelligenceController extends Controller
{
    public function index(
        Request $request,
        ConversationIntelligenceService $service,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'analysis_status' => ['sometimes', 'in:pending,analyzed'],
            'min_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'max_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'owner_user_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($service->listForCompany($tenantContext->companyId($request), $filters));
    }

    public function show(
        Request $request,
        int $conversation,
        ConversationIntelligenceService $service,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $detail = $service->detailForCompany($tenantContext->companyId($request), $conversation);

        if (! $detail) {
            return response()->json(['message' => 'Conversa não encontrada.'], 404);
        }

        return response()->json(['data' => $detail]);
    }

    public function summary(
        Request $request,
        ConversationIntelligenceService $service,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        return response()->json($service->summaryForCompany($tenantContext->companyId($request)));
    }

    public function analyze(Request $request, int $conversation, ConversationIntelligenceService $service): JsonResponse
    {
        try {
            $score = $service->analyzeForCompany((int) $request->user()->company_id, $conversation);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Conversa não encontrada.'], 404);
        } catch (ConversationAnalyzerUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $score->analysis_version === 1
                ? 'Conversa analisada com sucesso.'
                : 'Conversa reanalisada. O snapshot anterior foi preservado.',
            'data' => $service->detailForCompany((int) $request->user()->company_id, $conversation),
        ], 201);
    }
}
