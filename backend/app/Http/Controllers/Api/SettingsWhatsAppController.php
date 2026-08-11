<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MetaEmbeddedSignupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteWhatsAppEmbeddedSignupRequest;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\EffectiveTenantContext;
use App\Services\WhatsApp\WhatsAppEmbeddedSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsWhatsAppController extends Controller
{
    public function completeEmbeddedSignup(
        CompleteWhatsAppEmbeddedSignupRequest $request,
        WhatsAppEmbeddedSignupService $service,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $integration = $service->complete(
                (int) $request->user()->company_id,
                $validated['code'],
                $validated['embedded_signup']['data'],
            );
        } catch (MetaEmbeddedSignupException $exception) {
            $response = [
                'message' => $exception->getMessage(),
            ];

            if ($metaError = $exception->safeMetaError()) {
                $response['meta_error'] = $metaError;
            }

            return response()->json($response, $exception->httpStatus);
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

    public function update(Request $request, CompanyWhatsAppIntegrationService $service): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in([CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD])],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'phone_number_id' => ['nullable', 'string', 'max:255'],
            'business_account_id' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string', 'max:5000'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'last_error' => ['nullable', 'string', 'max:2000'],
        ]);

        $companyId = (int) $request->user()->company_id;

        return response()->json([
            'data' => $service->upsertForCompany($companyId, $validated),
        ]);
    }
}
