<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request, DashboardMetricsService $dashboardMetricsService): JsonResponse
    {
        $companyId = $request->user()->company_id;

        return response()->json($dashboardMetricsService->summaryForCompany($companyId));
    }
}
