<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\DashboardMetricsService;
use App\Services\EffectiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(
        Request $request,
        DashboardMetricsService $dashboardMetricsService,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $user = $request->user();
        $summary = $user->dataScope() === 'own'
            ? $dashboardMetricsService->summaryForUser($user)
            : $dashboardMetricsService->summaryForCompany($tenantContext->companyId($request));

        return response()->json($summary);
    }
}
