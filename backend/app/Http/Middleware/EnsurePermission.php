<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        if (($user->role?->value ?? $user->role) === UserRole::PLATFORM_ADMIN->value) {
            if ($request->isMethod('GET') && $request->route()?->getName()) {
                return $next($request);
            }
            return response()->json(['message' => 'O contexto administrativo permite somente consultas autorizadas.'], 403);
        }

        if (! $user->active || ! collect($permissions)->contains(fn (string $permission) => $user->hasPermission($permission))) {
            return response()->json(['message' => 'Você não possui a permissão necessária.'], 403);
        }

        return $next($request);
    }
}
