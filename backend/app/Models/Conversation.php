<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'lead_id',
        'owner_user_id',
        'status',
        'started_at',
        'last_message_at',
        'closed_at',
        'ai_summary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function qualityScores(): HasMany
    {
        return $this->hasMany(ConversationQualityScore::class)->orderByDesc('analysis_version');
    }

    public function latestQualityScore(): HasOne
    {
        return $this->hasOne(ConversationQualityScore::class)->ofMany('analysis_version', 'max');
    }
}
