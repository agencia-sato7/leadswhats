<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'owner_user_id',
        'name',
        'phone_e164',
        'source',
        'source_method',
        'source_updated_at',
        'creative_id',
        'creative_url',
        'creative_description',
        'campaign_name',
        'is_repeat_lead',
        'first_inbound_at',
        'last_inbound_at',
        'last_outbound_at',
        'first_response_seconds',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_repeat_lead' => 'boolean',
            'first_inbound_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
