<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlatformCompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCompanyController extends Controller
{
    public function index(PlatformCompanyService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->listCompanies(),
        ]);
    }

    public function store(Request $request, PlatformCompanyService $service): JsonResponse
    {
        $validated = $request->validate([
            'company' => ['required', 'array'],
            'company.name' => ['required', 'string', 'max:255'],
            'company.slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:companies,slug'],

            'admin_user' => ['required', 'array'],
            'admin_user.name' => ['required', 'string', 'max:255'],
            'admin_user.email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_user.password' => ['required', 'string', 'min:8'],

            'settings' => ['required', 'array'],
            'settings.timezone' => ['required', 'string', 'max:100'],
            'settings.workday_start_time' => ['required', 'date_format:H:i:s'],
            'settings.workday_end_time' => ['required', 'date_format:H:i:s'],
            'settings.lunch_start_time' => ['nullable', 'date_format:H:i:s'],
            'settings.lunch_end_time' => ['nullable', 'date_format:H:i:s'],
            'settings.working_days' => ['required', 'array', 'min:1'],
            'settings.working_days.*' => ['integer', Rule::in([0, 1, 2, 3, 4, 5, 6])],
            'settings.repeated_lead_window_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'settings.rescue_threshold_hours' => ['required', 'integer', 'min:1', 'max:10000'],
            'settings.first_response_sla_minutes' => ['required', 'integer', 'min:1', 'max:100000'],
            'settings.follow_up_sla_hours' => ['required', 'integer', 'min:1', 'max:100000'],
            'settings.stale_conversation_hours' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $payload = $service->createCompany($validated);

        return response()->json([
            'message' => 'Company created successfully.',
            'data' => $payload,
        ], 201);
    }

    public function show(int $companyId, PlatformCompanyService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->showCompany($companyId),
        ]);
    }

    public function update(Request $request, int $companyId, PlatformCompanyService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('companies', 'slug')->ignore($companyId)],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);

        $payload = $service->updateCompany($companyId, $validated);

        return response()->json([
            'message' => 'Company updated successfully.',
            'data' => $payload,
        ]);
    }
}

