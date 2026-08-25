<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CampaignIntelligenceEvidence extends Model
{
    protected $table = 'campaign_intelligence_evidences';

    protected $fillable = [
        'company_id', 'lead_id', 'conversation_id', 'owner_user_id', 'owner_name', 'stage_name', 'analysis_date',
        'input_hash', 'score', 'criteria_scores', 'summary', 'positive_points', 'errors',
        'improvement_suggestion', 'message_count', 'inbound_count', 'outbound_count',
        'rescue_attempts', 'prompt_version', 'model_provider', 'model_name', 'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'analysis_date' => 'date',
            'criteria_scores' => 'array',
            'positive_points' => 'array',
            'errors' => 'array',
            'score' => 'integer',
            'analyzed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Evidências de Inteligência da Campanha são imutáveis.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Evidências de Inteligência da Campanha não podem ser removidas individualmente.');
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
