<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReport extends Model
{
    protected $fillable = [
        'company_id',
        'report_date',
        'metrics',
        'quality',
        'executive_summary',
        'overall_verdict',
        'report_payload',
        'recipients',
        'status',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'metrics' => 'array',
            'quality' => 'array',
            'report_payload' => 'array',
            'recipients' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
