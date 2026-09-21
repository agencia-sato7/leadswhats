<?php

namespace App\Services;

use App\Models\AccessProfile;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    public function __construct(private readonly AccessAuditService $audit) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        return User::query()->where('role', '!=', 'platform_admin')->with(['accessProfile:id,name,data_scope,is_full_access', 'company:id,name'])
            ->when($filters['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($filters['profile_id'] ?? null, fn ($q, $id) => $q->where('access_profile_id', $id))
            ->when(isset($filters['active']), fn ($q) => $q->where('active', $filters['active']))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(fn ($sub) => $sub->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })->orderBy('name')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function create(Request $request, array $data): User
    {
        $profile = $this->profileForCompany((int) $data['access_profile_id'], (int) $data['company_id']);
        return DB::transaction(function () use ($request, $data, $profile): User {
            $user = User::query()->create([
                'company_id' => $data['company_id'], 'access_profile_id' => $profile->id,
                'name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']),
                'role' => $this->legacyRole($profile), 'active' => true, 'must_change_password' => true,
            ]);
            $this->audit->record($request, 'user.created', 'user', $user->id, $user->company_id, null, $this->snapshot($user));
            return $user->load('accessProfile', 'company');
        });
    }

    public function update(Request $request, User $user, array $data): User
    {
        $this->guardTenantUser($user);
        $before = $this->snapshot($user);
        if (isset($data['access_profile_id'])) {
            $profile = $this->profileForCompany((int) $data['access_profile_id'], (int) $user->company_id);
            $data['role'] = $this->legacyRole($profile);
        }
        $user->fill($data)->save();
        $this->audit->record($request, 'user.updated', 'user', $user->id, $user->company_id, $before, $this->snapshot($user));
        return $user->load('accessProfile', 'company');
    }

    public function deactivate(Request $request, User $user): User
    {
        $this->guardTenantUser($user);
        $before = $this->snapshot($user);
        DB::transaction(function () use ($user): void {
            $user->update(['active' => false]);
            ApiToken::query()->where('user_id', $user->id)->delete();
        });
        $this->audit->record($request, 'user.deactivated', 'user', $user->id, $user->company_id, $before, $this->snapshot($user));
        return $user;
    }

    public function reactivate(Request $request, User $user): User
    {
        $this->guardTenantUser($user);
        $before = $this->snapshot($user);
        $user->update(['active' => true]);
        $this->audit->record($request, 'user.reactivated', 'user', $user->id, $user->company_id, $before, $this->snapshot($user));
        return $user;
    }

    public function resetPassword(Request $request, User $user, string $password): void
    {
        $this->guardTenantUser($user);
        DB::transaction(function () use ($user, $password): void {
            $user->update(['password' => Hash::make($password), 'must_change_password' => true]);
            ApiToken::query()->where('user_id', $user->id)->delete();
        });
        $this->audit->record($request, 'user.password_reset', 'user', $user->id, $user->company_id, null, ['must_change_password' => true]);
    }

    private function profileForCompany(int $profileId, int $companyId): AccessProfile
    {
        $profile = AccessProfile::query()->whereKey($profileId)->where('company_id', $companyId)->where('active', true)->first();
        if (! $profile) throw ValidationException::withMessages(['access_profile_id' => ['Perfil inválido para a clínica selecionada.']]);
        return $profile;
    }

    private function guardTenantUser(User $user): void
    {
        if (($user->role?->value ?? $user->role) === 'platform_admin') abort(404);
    }

    private function legacyRole(AccessProfile $profile): string
    {
        return match ($profile->slug) { 'administrador' => 'admin', 'gestor' => 'gestor', 'sdr' => 'sdr', default => 'custom' };
    }

    private function snapshot(User $user): array
    {
        return $user->only(['id', 'company_id', 'access_profile_id', 'name', 'email', 'role', 'active', 'must_change_password']);
    }
}
