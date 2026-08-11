<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlatformTenantViewContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCompanyViewContextController extends Controller
{
    public function store(
        Request $request,
        int $companyId,
        PlatformTenantViewContextService $service,
    ): JsonResponse {
        return response()->json([
            'data' => $service->start(
                $request->user(),
                $companyId,
                $request->ip(),
                $request->userAgent(),
            ),
        ], 201);
    }

    public function destroy(Request $request, PlatformTenantViewContextService $service): JsonResponse
    {
        $plainContext = trim((string) $request->header('X-Tenant-Context', ''));
        if ($plainContext === '') {
            return response()->json(['message' => 'Contexto de clínica ausente.'], 422);
        }

        $revoked = $service->revoke(
            $request->user(),
            $plainContext,
            $request->ip(),
            $request->userAgent(),
        );

        if (! $revoked) {
            return response()->json([
                'message' => 'Contexto de clínica inválido, expirado ou revogado.',
            ], 403);
        }

        return response()->json(['message' => 'Contexto de clínica encerrado.']);
    }
}
