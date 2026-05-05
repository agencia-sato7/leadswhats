<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = $request->bearerToken();

        if (!$rawToken) {
            return response()->json(['message' => 'Token ausente.'], 401);
        }

        $tokenHash = hash('sha256', $rawToken);

        $apiToken = ApiToken::with('user')
            ->where('token_hash', $tokenHash)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$apiToken || !$apiToken->user) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        $apiToken->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $apiToken->user);

        return $next($request);
    }
}
