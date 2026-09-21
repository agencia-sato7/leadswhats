<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessProfile extends Model
{
    protected $fillable = [
        'company_id', 'source_profile_id', 'name', 'slug', 'description', 'data_scope',
        'version', 'is_system', 'is_full_access', 'active',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'is_system' => 'boolean',
            'is_full_access' => 'boolean', 'active' => 'boolean',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function sourceProfile(): BelongsTo { return $this->belongsTo(self::class, 'source_profile_id'); }
    public function permissions(): BelongsToMany { return $this->belongsToMany(Permission::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
}
