<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessProfile;
use App\Models\Company;
use App\Models\Permission;
use App\Services\AccessAuditService;
use App\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminAccessProfileController extends Controller
{
    public function index(Request $request, AccessControlService $service): JsonResponse
    {
        $filters = $request->validate(['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'templates' => ['nullable', 'boolean'], 'active' => ['nullable', 'boolean']]);
        $query = AccessProfile::query()->with(['permissions:id,code,name,group_name', 'company:id,name', 'sourceProfile:id,name,version'])->withCount('users');
        if (($filters['templates'] ?? false) === true) $query->whereNull('company_id');
        elseif (array_key_exists('company_id', $filters)) $query->where('company_id', $filters['company_id']);
        if (array_key_exists('active', $filters)) $query->where('active', $filters['active']);
        return response()->json(['data' => $query->orderByRaw('company_id IS NOT NULL')->orderBy('name')->get()->map(fn ($p) => $service->profilePayload($p))->all()]);
    }

    public function store(Request $request, AccessControlService $service, AccessAuditService $audit): JsonResponse
    {
        $data = $this->validated($request);
        $slug = Str::slug($data['slug'] ?? $data['name']);
        $this->ensureUnique($data['company_id'] ?? null, $slug);
        $profile = DB::transaction(function () use ($data, $slug) {
            $profile = AccessProfile::query()->create([
                'company_id' => $data['company_id'] ?? null, 'name' => $data['name'], 'slug' => $slug,
                'description' => $data['description'] ?? null, 'data_scope' => $data['data_scope'],
                'version' => 1, 'is_system' => false, 'is_full_access' => false, 'active' => true,
            ]);
            $profile->permissions()->sync(Permission::query()->whereIn('code', $data['permissions'])->pluck('id'));
            return $profile;
        });
        $audit->record($request, 'profile.created', 'access_profile', $profile->id, $profile->company_id, null, $service->profilePayload($profile));
        return response()->json(['data' => $service->profilePayload($profile)], 201);
    }

    public function update(Request $request, AccessProfile $accessProfile, AccessControlService $service, AccessAuditService $audit): JsonResponse
    {
        $this->guardMutable($accessProfile);
        $data = $this->validated($request, true);
        unset($data['company_id'], $data['slug']);
        $before = $service->profilePayload($accessProfile);
        DB::transaction(function () use ($accessProfile, $data): void {
            $accessProfile->fill(collect($data)->except('permissions')->all());
            $accessProfile->version++;
            $accessProfile->save();
            if (isset($data['permissions'])) {
                $accessProfile->permissions()->sync(Permission::query()->whereIn('code', $data['permissions'])->pluck('id'));
            }
        });
        $accessProfile->unsetRelation('permissions');
        $audit->record($request, 'profile.updated', 'access_profile', $accessProfile->id, $accessProfile->company_id, $before, $service->profilePayload($accessProfile));
        return response()->json(['data' => $service->profilePayload($accessProfile)]);
    }

    public function destroy(Request $request, AccessProfile $accessProfile, AccessControlService $service, AccessAuditService $audit): JsonResponse
    {
        $this->guardMutable($accessProfile);
        if ($accessProfile->users()->exists()) return response()->json(['message' => 'Reassocie os usuários antes de arquivar este perfil.'], 409);
        $before = $service->profilePayload($accessProfile);
        $accessProfile->update(['active' => false]);
        $audit->record($request, 'profile.archived', 'access_profile', $accessProfile->id, $accessProfile->company_id, $before, $service->profilePayload($accessProfile));
        return response()->json(['message' => 'Perfil arquivado.']);
    }

    public function copy(Request $request, AccessProfile $accessProfile, AccessControlService $service, AccessAuditService $audit): JsonResponse
    {
        if ($accessProfile->company_id !== null) throw ValidationException::withMessages(['profile' => ['Somente modelos globais podem ser copiados.']]);
        $data = $request->validate(['company_ids' => ['required', 'array', 'min:1'], 'company_ids.*' => ['integer', 'distinct', 'exists:companies,id']]);
        $accessProfile->load('permissions');
        $created = [];
        foreach ($data['company_ids'] as $companyId) {
            $exists = AccessProfile::query()->where('company_id', $companyId)->where('slug', $accessProfile->slug)->exists();
            if ($exists) continue;
            $copy = DB::transaction(function () use ($accessProfile, $companyId) {
                $copy = $accessProfile->replicate(['company_id', 'source_profile_id']);
                $copy->company_id = $companyId; $copy->source_profile_id = $accessProfile->id; $copy->is_system = $accessProfile->is_system;
                $copy->save(); $copy->permissions()->sync($accessProfile->permissions->modelKeys()); return $copy;
            });
            $audit->record($request, 'profile.copied', 'access_profile', $copy->id, $companyId, null, $service->profilePayload($copy));
            $created[] = $service->profilePayload($copy);
        }
        return response()->json(['data' => $created], 201);
    }

    public function syncPreview(Request $request, AccessProfile $accessProfile): JsonResponse
    {
        if ($accessProfile->company_id !== null) abort(422, 'Selecione um modelo global.');
        $data = $request->validate(['company_ids' => ['required', 'array'], 'company_ids.*' => ['integer', 'exists:companies,id']]);
        $copies = AccessProfile::query()->where('source_profile_id', $accessProfile->id)->whereIn('company_id', $data['company_ids'])->withCount('users')->get()->keyBy('company_id');
        return response()->json(['data' => Company::query()->whereIn('id', $data['company_ids'])->get(['id', 'name'])->map(fn ($company) => [
            'company_id' => $company->id, 'company_name' => $company->name, 'profile_id' => $copies->get($company->id)?->id,
            'current_version' => $copies->get($company->id)?->version, 'target_version' => $accessProfile->version,
            'affected_users' => (int) ($copies->get($company->id)?->users_count ?? 0),
        ])->all()]);
    }

    public function sync(Request $request, AccessProfile $accessProfile, AccessControlService $service, AccessAuditService $audit): JsonResponse
    {
        if ($accessProfile->company_id !== null) abort(422, 'Selecione um modelo global.');
        $data = $request->validate(['company_ids' => ['required', 'array', 'min:1'], 'company_ids.*' => ['integer', 'exists:companies,id']]);
        $accessProfile->load('permissions'); $updated = [];
        foreach (AccessProfile::query()->where('source_profile_id', $accessProfile->id)->whereIn('company_id', $data['company_ids'])->get() as $copy) {
            if ($copy->is_system && $copy->slug === 'administrador') continue;
            $before = $service->profilePayload($copy);
            $copy->update(['name' => $accessProfile->name, 'description' => $accessProfile->description, 'data_scope' => $accessProfile->data_scope, 'version' => $accessProfile->version, 'active' => $accessProfile->active]);
            $copy->permissions()->sync($accessProfile->permissions->modelKeys()); $copy->unsetRelation('permissions');
            $audit->record($request, 'profile.synced', 'access_profile', $copy->id, $copy->company_id, $before, $service->profilePayload($copy));
            $updated[] = $service->profilePayload($copy);
        }
        return response()->json(['data' => $updated]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'company_id' => [$partial ? 'sometimes' : 'nullable', 'nullable', 'integer', 'exists:companies,id'],
            'name' => [$prefix, 'string', 'max:255'], 'slug' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'], 'data_scope' => [$prefix, Rule::in(['own', 'company'])],
            'permissions' => [$prefix, 'array'], 'permissions.*' => ['string', 'distinct', 'exists:permissions,code'],
        ]);
    }

    private function guardMutable(AccessProfile $profile): void
    {
        if ($profile->is_system && $profile->slug === 'administrador') abort(409, 'O perfil Administrador é protegido.');
    }

    private function ensureUnique(?int $companyId, string $slug): void
    {
        $exists = AccessProfile::query()->where('slug', $slug)->where(function ($q) use ($companyId) {
            $companyId === null ? $q->whereNull('company_id') : $q->where('company_id', $companyId);
        })->exists();
        if ($exists) throw ValidationException::withMessages(['slug' => ['Já existe um perfil com este identificador neste escopo.']]);
    }
}
