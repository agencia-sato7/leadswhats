<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

class CampaignIntelligenceReport extends Model
{
    protected $fillable = [
        'company_id', 'requested_by_user_id', 'base_report_id', 'start_date', 'end_date',
        'comparison_start_date', 'comparison_end_date', 'status', 'progress_stage',
        'progress_percentage', 'input_fingerprint', 'metrics', 'result',
        'reused_evidence_count', 'new_evidence_count', 'prompt_version', 'model_provider',
        'model_name', 'failure_message', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'comparison_start_date' => 'date',
            'comparison_end_date' => 'date',
            'metrics' => 'array',
            'result' => 'array',
            'progress_percentage' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (CampaignIntelligenceReport $report): void {
            if ($report->getOriginal('status') === 'completed') {
                throw new LogicException('Snapshots concluídos de Inteligência da Campanha são imutáveis.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Snapshots de Inteligência da Campanha não podem ser removidos individualmente.');
        });
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function baseReport(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_report_id');
    }

    public function evidences(): BelongsToMany
    {
        return $this->belongsToMany(
            CampaignIntelligenceEvidence::class,
            'campaign_intelligence_report_evidence'
        )->withPivot('cohort')->withTimestamps();
    }
}
