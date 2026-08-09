<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ConversationQualityScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'conversation_id',
        'lead_id',
        'owner_user_id',
        'score',
        'summary',
        'intent',
        'objections',
        'positive_points',
        'errors',
        'improvement_suggestion',
        'commercial_data',
        'criteria_scores',
        'recommended_kanban_column_id',
        'classification_reason',
        'confidence',
        'analysis_version',
        'prompt_version',
        'model_provider',
        'model_name',
        'source_last_message_id',
        'transcript_hash',
        'analyzed_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Snapshots de Conversation Intelligence são imutáveis. Crie uma nova análise.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Snapshots de Conversation Intelligence não podem ser removidos individualmente.');
        });
    }

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'objections' => 'array',
            'positive_points' => 'array',
            'errors' => 'array',
            'commercial_data' => 'array',
            'criteria_scores' => 'array',
            'confidence' => 'float',
            'analysis_version' => 'integer',
            'analyzed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function recommendedKanbanColumn(): BelongsTo
    {
        return $this->belongsTo(KanbanColumn::class, 'recommended_kanban_column_id');
    }

    public function sourceLastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_last_message_id');
    }
}
