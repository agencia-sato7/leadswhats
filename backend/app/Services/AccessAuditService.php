<?php

namespace App\Services;

use App\Models\AccessAuditLog;
use Illuminate\Http\Request;

class AccessAuditService
{
    public function record(Request $request, string $event, string $subjectType, ?int $subjectId, ?int $companyId, ?array $before = null, ?array $after = null): void
    {
        AccessAuditLog::query()->create([
            'actor_user_id' => $request->user()?->id,
            'company_id' => $companyId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'before' => $this->sanitize($before),
            'after' => $this->sanitize($after),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function sanitize(?array $value): ?array
    {
        if ($value === null) return null;
        unset($value['password'], $value['password_confirmation'], $value['remember_token']);
        return $value;
    }
}
