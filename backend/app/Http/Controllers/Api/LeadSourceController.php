<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSourceHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadSourceController extends Controller
{
    public function unknown(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $leads = Lead::query()
            ->where('company_id', $companyId)
            ->where('source', 'desconhecido')
            ->orderByDesc('last_inbound_at')
            ->limit(100)
            ->get(['id', 'name', 'phone_e164', 'source', 'source_method', 'last_inbound_at', 'created_at']);

        return response()->json(['data' => $leads]);
    }

    public function recent(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $leads = Lead::query()
            ->where('company_id', $companyId)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'name', 'phone_e164', 'source', 'source_method', 'last_inbound_at', 'updated_at']);

        return response()->json(['data' => $leads]);
    }

    public function classify(Request $request, int $leadId): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $companyId = $request->user()->company_id;
        $lead = Lead::where('company_id', $companyId)->findOrFail($leadId);

        $newSource = strtolower(trim($validated['source']));
        $previousSource = $lead->source;

        if ($previousSource !== $newSource) {
            LeadSourceHistory::create([
                'company_id' => $companyId,
                'lead_id' => $lead->id,
                'previous_source' => $previousSource,
                'new_source' => $newSource,
                'change_type' => 'manual',
                'reason' => $validated['reason'] ?? 'Classificação manual pelo atendente.',
                'changed_by_user_id' => $request->user()->id,
                'changed_at' => now(),
            ]);

            $lead->source = $newSource;
        }

        $lead->source_method = 'manual';
        $lead->source_updated_at = now();
        $lead->save();

        return response()->json([
            'message' => 'Origem classificada com sucesso.',
            'lead' => $lead->only(['id', 'source', 'source_method', 'source_updated_at']),
        ]);
    }
}
