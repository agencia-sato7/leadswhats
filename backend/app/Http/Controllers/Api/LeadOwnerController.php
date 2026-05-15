<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Domain\LeadOwnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadOwnerController extends Controller
{
    public function update(Request $request, int $leadId, LeadOwnerService $leadOwnerService): JsonResponse
    {
        $validated = $request->validate([
            'owner_user_id' => ['present', 'nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $payload = $leadOwnerService->updateLeadOwner(
            $request->user(),
            $leadId,
            $validated['owner_user_id'],
            $validated['reason'],
        );

        return response()->json([
            'message' => 'Lead owner updated successfully.',
            'data' => $payload,
        ]);
    }
}
