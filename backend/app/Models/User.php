<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\PermissionCatalog;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'company_id',
        'access_profile_id',
        'name',
        'email',
        'password',
        'role',
        'active',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'role' => UserRole::class,
        ];
    }

    public function accessProfile(): BelongsTo
    {
        return $this->belongsTo(AccessProfile::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function hasPermission(string $permission): bool
    {
        $profile = $this->relationLoaded('accessProfile')
            ? $this->accessProfile
            : $this->accessProfile()->with('permissions:id,code')->first();

        if ($profile) {
            return (bool) ($profile->active && ($profile->is_full_access || $profile->permissions->contains('code', $permission)));
        }

        return match ($this->role?->value ?? $this->role) {
            'admin', 'gestor' => in_array($permission, PermissionCatalog::gestor(), true),
            'sdr' => in_array($permission, PermissionCatalog::sdr(), true),
            default => false,
        };
    }

    public function dataScope(): string
    {
        return $this->accessProfile?->data_scope ?? (($this->role?->value ?? $this->role) === 'sdr' ? 'own' : 'company');
    }
}
