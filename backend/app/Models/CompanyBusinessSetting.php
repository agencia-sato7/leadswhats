<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyBusinessSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        "company_id",
        "timezone",
        "workday_start_time",
        "workday_end_time",
        "lunch_start_time",
        "lunch_end_time",
        "working_days",
        "repeated_lead_window_days",
        "rescue_threshold_hours",
        "webhook_token",
    ];

    protected function casts(): array
    {
        return [
            "working_days" => "array",
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
