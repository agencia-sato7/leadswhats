<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Services\EffectiveTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadStageHistoryController extends Controller
{
    public function index(Request $request, int $leadId, EffectiveTenantContext $tenantContext): JsonResponse
    {
        $companyId = $tenantContext->companyId($request);

        $lead = Lead::query()
            ->where('company_id', $companyId)
            ->when($request->user()->dataScope() === 'own', function ($query) use ($request) {
                $query->where(function ($scope) use ($request) {
                    $scope->where('owner_user_id', $request->user()->id)
                        ->orWhereHas('conversations', fn ($conversation) => $conversation->where('owner_user_id', $request->user()->id));
                });
            })
            ->findOrFail($leadId, ['id']);

        $history = LeadStageHistory::query()
            ->from('lead_stage_histories as h')
            ->leftJoin('kanban_columns as from_col', 'from_col.id', '=', 'h.from_column_id')
            ->leftJoin('kanban_columns as to_col', 'to_col.id', '=', 'h.to_column_id')
            ->where('h.company_id', $companyId)
            ->where('h.lead_id', $lead->id)
            ->orderByDesc('h.moved_at')
            ->orderByDesc('h.id')
            ->get([
                'h.id',
                'h.lead_id',
                'h.from_column_id',
                'from_col.name as from_column_name',
                'h.to_column_id',
                'to_col.name as to_column_name',
                'h.moved_by_user_id',
                'h.move_source',
                'h.reason',
                'h.moved_at',
            ]);

        return response()->json(['data' => $history]);
    }
}
