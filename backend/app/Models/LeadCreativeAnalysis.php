<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadCreativeAnalysis extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'lead_id',
        'creative_id',
        'creative_url',
        'platform',
        'description',
        'headline',
        'cta',
        'image_url',
        'status',
        'metadata',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
