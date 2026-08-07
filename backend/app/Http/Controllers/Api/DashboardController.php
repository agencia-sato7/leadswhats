<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Domain\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request, DashboardMetricsService $dashboardMetricsService): JsonResponse
    {
        $user = $request->user();
        $role = $user->role?->value ?? (string) $user->role;

        $summary = $role === UserRole::SDR->value
            ? $dashboardMetricsService->summaryForUser($user)
            : $dashboardMetricsService->summaryForCompany((int) $user->company_id);

        return response()->json($summary);
    }
}
