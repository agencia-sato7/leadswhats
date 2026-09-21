<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request, AdminUserService $service): JsonResponse
    {
        $filters = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'profile_id' => ['nullable', 'integer', 'exists:access_profiles,id'],
            'active' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $service->paginate($filters);
        return response()->json([
            'data' => collect($page->items())->map(fn (User $user) => $this->payload($user))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(Request $request, AdminUserService $service): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'access_profile_id' => ['required', 'integer', 'exists:access_profiles,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);
        return response()->json(['data' => $this->payload($service->create($request, $data))], 201);
    }

    public function update(Request $request, User $user, AdminUserService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'access_profile_id' => ['sometimes', 'required', 'integer', 'exists:access_profiles,id'],
        ]);
        return response()->json(['data' => $this->payload($service->update($request, $user, $data))]);
    }

    public function destroy(Request $request, User $user, AdminUserService $service): JsonResponse
    {
        return response()->json(['message' => 'Usuário desativado.', 'data' => $this->payload($service->deactivate($request, $user))]);
    }

    public function restore(Request $request, User $user, AdminUserService $service): JsonResponse
    {
        return response()->json(['message' => 'Usuário reativado.', 'data' => $this->payload($service->reactivate($request, $user))]);
    }

    public function resetPassword(Request $request, User $user, AdminUserService $service): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:255']]);
        $service->resetPassword($request, $user, $data['password']);
        return response()->json(['message' => 'Senha temporária redefinida e sessões revogadas.']);
    }

    private function payload(User $user): array
    {
        $user->loadMissing('company:id,name', 'accessProfile:id,name,data_scope,is_full_access');
        return [
            'id' => $user->id, 'company_id' => $user->company_id, 'company_name' => $user->company?->name,
            'name' => $user->name, 'email' => $user->email, 'role' => $user->role?->value ?? $user->role,
            'active' => (bool) $user->active, 'must_change_password' => (bool) $user->must_change_password,
            'access_profile' => $user->accessProfile ? [
                'id' => $user->accessProfile->id, 'name' => $user->accessProfile->name,
                'data_scope' => $user->accessProfile->data_scope, 'is_full_access' => $user->accessProfile->is_full_access,
            ] : null,
            'created_at' => optional($user->created_at)?->toISOString(),
        ];
    }
}
