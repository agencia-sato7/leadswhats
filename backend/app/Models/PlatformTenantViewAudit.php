<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformTenantViewAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_tenant_view_context_id',
        'platform_admin_user_id',
        'company_id',
        'event',
        'reason',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(PlatformTenantViewContext::class, 'platform_tenant_view_context_id');
    }
}
