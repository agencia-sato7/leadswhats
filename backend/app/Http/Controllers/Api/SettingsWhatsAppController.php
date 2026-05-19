<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyWhatsAppIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsWhatsAppController extends Controller
{
    public function show(Request $request, CompanyWhatsAppIntegrationService $service): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        return response()->json([
            "data" => $service->getForCompany($companyId),
        ]);
    }

    public function update(Request $request, CompanyWhatsAppIntegrationService $service): JsonResponse
    {
        $validated = $request->validate([
            "provider" => ["required", "string", Rule::in([CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD])],
            "phone_number" => ["nullable", "string", "max:30"],
            "phone_number_id" => ["nullable", "string", "max:255"],
            "business_account_id" => ["nullable", "string", "max:255"],
            "access_token" => ["nullable", "string", "max:5000"],
            "webhook_verify_token" => ["nullable", "string", "max:255"],
            "last_error" => ["nullable", "string", "max:2000"],
        ]);

        $companyId = (int) $request->user()->company_id;

        return response()->json([
            "data" => $service->upsertForCompany($companyId, $validated),
        ]);
    }
}
