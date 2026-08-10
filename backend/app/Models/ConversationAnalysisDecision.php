<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConversationAnalysisDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'conversation_quality_score_id',
        'conversation_id',
        'lead_id',
        'decided_by_user_id',
        'decision',
        'from_column_id',
        'recommended_column_id',
        'applied_column_id',
        'reason',
        'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
