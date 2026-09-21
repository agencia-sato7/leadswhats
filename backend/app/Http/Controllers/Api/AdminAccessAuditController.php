<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccessAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['company_id' => ['nullable', 'integer'], 'subject_type' => ['nullable', 'string'], 'subject_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = AccessAuditLog::query()->with('actor:id,name,email', 'company:id,name')
            ->when($filters['company_id'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->latest('id')->paginate(30);
        return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }
}
