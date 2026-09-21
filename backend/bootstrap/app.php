<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\ResolveTenantViewContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.token' => AuthenticateApiToken::class,
            'role' => EnsureUserRole::class,
            'permission' => EnsurePermission::class,
            'password.changed' => EnsurePasswordChanged::class,
            'tenant.view' => ResolveTenantViewContext::class,
            'whatsapp.connected' => \App\Http\Middleware\EnsureWhatsAppConnected::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
