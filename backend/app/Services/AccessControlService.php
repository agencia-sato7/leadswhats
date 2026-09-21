<?php

namespace App\Services;

use App\Models\AccessProfile;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccessControlService
{
    /** @return array<string,mixed>|null */
    public function profilePayload(?AccessProfile $profile): ?array
    {
        if (! $profile) return null;
        $profile->loadMissing(['permissions:id,code,name,group_name', 'company:id,name', 'sourceProfile:id,name,version']);

        return [
            'id' => $profile->id,
            'company_id' => $profile->company_id,
            'company_name' => $profile->company?->name,
            'source_profile_id' => $profile->source_profile_id,
            'source_version' => $profile->sourceProfile?->version,
            'name' => $profile->name,
            'slug' => $profile->slug,
            'description' => $profile->description,
            'data_scope' => $profile->data_scope,
            'version' => $profile->version,
            'is_system' => $profile->is_system,
            'is_full_access' => $profile->is_full_access,
            'active' => $profile->active,
            'users_count' => isset($profile->users_count) ? (int) $profile->users_count : $profile->users()->count(),
            'permissions' => $profile->permissions->pluck('code')->sort()->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function userAccessPayload(User $user): array
    {
        $user->loadMissing('accessProfile.permissions:id,code');
        $profile = $user->accessProfile;
        return [
            'access_profile' => $this->profilePayload($profile),
            'permissions' => $profile?->is_full_access
                ? Permission::query()->orderBy('code')->pluck('code')->all()
                : ($profile?->permissions->pluck('code')->sort()->values()->all() ?? []),
            'data_scope' => $profile?->data_scope ?? $user->dataScope(),
            'must_change_password' => (bool) $user->must_change_password,
        ];
    }

    /** @return array<string,AccessProfile> */
    public function createProfilesForCompany(Company $company): array
    {
        return DB::transaction(function () use ($company): array {
            $result = [];
            $templates = AccessProfile::query()->whereNull('company_id')->where('active', true)->with('permissions')->get();
            foreach ($templates as $template) {
                $profile = AccessProfile::query()->firstOrCreate(
                    ['company_id' => $company->id, 'slug' => $template->slug],
                    [
                        'source_profile_id' => $template->id, 'name' => $template->name,
                        'description' => $template->description, 'data_scope' => $template->data_scope,
                        'version' => $template->version, 'is_system' => $template->is_system,
                        'is_full_access' => $template->is_full_access, 'active' => true,
                    ]
                );
                $profile->permissions()->sync($template->permissions->modelKeys());
                $result[$template->slug] = $profile;
            }
            return $result;
        });
    }
}
