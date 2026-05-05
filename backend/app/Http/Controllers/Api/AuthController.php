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
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'company_id' => $user->company_id,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'company_id' => $user->company_id,
        ]);
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
