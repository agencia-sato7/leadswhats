<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id', 'company_id', 'subject_type', 'subject_id', 'event',
        'before', 'after', 'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
