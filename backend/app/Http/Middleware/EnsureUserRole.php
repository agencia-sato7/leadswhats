<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        if (!in_array($user->role?->value ?? $user->role, $roles, true)) {
            return response()->json(['message' => 'Acesso negado para este perfil.'], 403);
        }

        return $next($request);
    }
}
