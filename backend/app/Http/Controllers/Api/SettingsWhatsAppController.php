<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MetaCoexistenceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteWhatsAppCoexistenceSignupRequest;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\EffectiveTenantContext;
use App\Services\WhatsApp\WhatsAppCoexistenceSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsWhatsAppController extends Controller
{
    public function disconnect(Request $request, \App\Services\WhatsApp\WhatsAppDisconnectService $service): JsonResponse
    {
        if ($request->hasHeader('X-Tenant-Context')) {
            return response()->json(['message' => 'O contexto administrativo é somente leitura.'], 403);
        }
        try {
            return response()->json(['data' => $service->disconnect(
                (int) $request->user()->company_id,
                (int) $request->user()->id,
            )]);
        } catch (MetaCoexistenceException $exception) {
            return $this->metaErrorResponse($exception);
        }
    }

    public function completeCoexistence(
        CompleteWhatsAppCoexistenceSignupRequest $request,
        WhatsAppCoexistenceSignupService $service,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $integration = $service->complete(
                (int) $request->user()->company_id,
                $validated['code'],
                $validated['coexistence']['data'],
            );
        } catch (MetaCoexistenceException $exception) {
            return $this->metaErrorResponse($exception);
        }

        return response()->json([
            'data' => $integration,
        ]);
    }

    public function requestSync(
        Request $request,
        WhatsAppCoexistenceSignupService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'sync_type' => ['required', 'string', Rule::in(['contacts', 'history', 'both'])],
        ]);

        $syncTypes = $validated['sync_type'] === 'both'
            ? ['contacts', 'history']
            : [$validated['sync_type']];

        try {
            $integration = $service->requestDataSync(
                (int) $request->user()->company_id,
                $syncTypes,
            );
        } catch (MetaCoexistenceException $exception) {
            return $this->metaErrorResponse($exception);
        }

        return response()->json([
            'data' => $integration,
        ]);
    }

    public function show(
        Request $request,
        CompanyWhatsAppIntegrationService $service,
        EffectiveTenantContext $tenantContext,
    ): JsonResponse {
        $companyId = $tenantContext->companyId($request);

        return response()->json([
            'data' => $service->getForCompany($companyId),
        ]);
    }

    private function metaErrorResponse(MetaCoexistenceException $exception): JsonResponse
    {
        $response = [
            'message' => $exception->getMessage(),
        ];

        if ($metaError = $exception->safeMetaError()) {
            $response['meta_error'] = $metaError;
        }

        return response()->json($response, $exception->httpStatus);
    }
}
