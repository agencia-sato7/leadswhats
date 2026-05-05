<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'work_start',
        'work_end',
        'lunch_start',
        'lunch_end',
        'active',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
