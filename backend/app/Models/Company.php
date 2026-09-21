<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        "name",
        "slug",
        "timezone",
        "work_start",
        "work_end",
        "lunch_start",
        "lunch_end",
        "active",
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class);
    }

    public function businessSetting(): HasOne
    {
        return $this->hasOne(CompanyBusinessSetting::class);
    }

    public function whatsappIntegration(): HasOne
    {
        return $this->hasOne(CompanyWhatsAppIntegration::class);
    }

    public function accessProfiles(): HasMany
    {
        return $this->hasMany(AccessProfile::class);
    }
}
