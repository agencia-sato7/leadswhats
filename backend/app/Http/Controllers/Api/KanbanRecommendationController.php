<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\KanbanRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KanbanRecommendationController extends Controller
{
    public function apply(Request $request, int $leadId, int $analysisId, KanbanRecommendationService $service): JsonResponse
    {
        $result = $service->apply($request->user(), $leadId, $analysisId);

        return response()->json([
            'message' => 'Recomendação da IA aplicada com sucesso.',
            'data' => [
                'decision' => $result['decision']->decision,
                'decided_at' => $result['decision']->decided_at,
                'movement' => $result['movement'],
            ],
        ]);
    }

    public function keepCurrent(Request $request, int $leadId, int $analysisId, KanbanRecommendationService $service): JsonResponse
    {
        $decision = $service->keepCurrent($request->user(), $leadId, $analysisId);

        return response()->json([
            'message' => 'Etapa atual mantida.',
            'data' => [
                'decision' => $decision->decision,
                'decided_at' => $decision->decided_at,
            ],
        ]);
    }
}
