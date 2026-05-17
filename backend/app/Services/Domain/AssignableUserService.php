<?php

namespace App\Services\Domain;

use App\Models\User;

class AssignableUserService
{
    /**
     * @return array<int, array{id:int,name:string,email:string,role:string|null}>
     */
    public function listForCompany(int $companyId): array
    {
        return User::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->whereIn('role', ['admin', 'gestor', 'sdr'])
            ->orderByRaw("CASE role WHEN 'admin' THEN 1 WHEN 'gestor' THEN 2 WHEN 'sdr' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'role'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'role' => $user->role?->value ?? (is_string($user->role) ? $user->role : null),
            ])
            ->all();
    }
}
