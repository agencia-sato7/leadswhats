<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\OperationalChecklistService;
use App\Services\EffectiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskChecklistController extends Controller
{
    public function index(
        Request $request,
        OperationalChecklistService $operationalChecklistService,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $items = $operationalChecklistService->checklistForUser(
            $request->user(),
            $tenantContext->companyId($request),
        );

        return response()->json([
            'data' => $items,
            'meta' => [
                'task_types' => ['vacuum_follow_up', 'waiting_first_response'],
                'limitations' => [],
            ],
        ]);
    }
}
