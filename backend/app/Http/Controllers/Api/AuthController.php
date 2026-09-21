<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly \App\Services\AccessControlService $accessControl,
        private readonly \App\Services\AccessAuditService $accessAudit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        if (!$user->active) {
            return response()->json(['message' => 'Usuário inativo.'], 403);
        }

        $plainToken = bin2hex(random_bytes(32));

        ApiToken::create([
            'user_id' => $user->id,
            'name' => $validated['device_name'] ?? 'web',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_in_seconds' => 60 * 60 * 24 * 30,
            'user' => array_merge([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'company_id' => $user->company_id,
                'company' => $user->company_id ? \App\Models\Company::query()->find($user->company_id, ['id', 'name', 'slug']) : null,
            ], $this->accessControl->userAccessPayload($user)),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(array_merge([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'company_id' => $user->company_id,
            'company' => $user->company_id ? \App\Models\Company::query()->find($user->company_id, ['id', 'name', 'slug']) : null,
        ], $this->accessControl->userAccessPayload($user)));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $user = $request->user();
        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['A senha atual está incorreta.']]);
        }
        $wasTemporary = (bool) $user->must_change_password;
        $user->forceFill(['password' => Hash::make($validated['password']), 'must_change_password' => false])->save();
        ApiToken::query()->where('user_id', $user->id)->where('token_hash', '!=', hash('sha256', (string) $request->bearerToken()))->delete();
        $this->accessAudit->record($request, 'user.password_changed', 'user', $user->id, $user->company_id, ['must_change_password' => $wasTemporary], ['must_change_password' => false]);
        return response()->json(['message' => 'Senha alterada com sucesso.', 'user' => array_merge([
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'role' => $user->role?->value, 'company_id' => $user->company_id,
        ], $this->accessControl->userAccessPayload($user))]);
    }

    public function logout(Request $request): JsonResponse
    {
        $rawToken = $request->bearerToken();

        if ($rawToken) {
            ApiToken::where('token_hash', hash('sha256', $rawToken))->delete();
        }

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }
}
