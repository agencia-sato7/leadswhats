<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\PlatformTenantViewContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantViewContext
{
    /**
     * Rotas que podem resolver uma clínica para leitura pelo platform_admin.
     * A lista fica no middleware para evitar ampliação acidental do contexto.
     *
     * @var list<string>
     */
    private const PLATFORM_READ_ROUTE_ALLOWLIST = [
        'tenant.bootstrap.overview',
        'tenant.dashboard.summary',
        'tenant.contacts.index',
        'tenant.inbox.conversations.index',
        'tenant.inbox.conversations.show',
        'tenant.inbox.conversations.events',
        'tenant.inbox.attachments.show',
        'tenant.tasks.checklist',
        'tenant.pipelines.index',
        'tenant.pipelines.kanban',
        'tenant.leads.stage-history',
        'tenant.intelligence.summary',
        'tenant.intelligence.conversations.index',
        'tenant.intelligence.conversations.show',
        'tenant.settings.whatsapp.show',
    ];

    public function __construct(private readonly PlatformTenantViewContextService $contextService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $plainContext = trim((string) $request->header('X-Tenant-Context', ''));
        $role = $user?->role?->value ?? $user?->role;

        if ($role !== UserRole::PLATFORM_ADMIN->value) {
            if ($plainContext !== '') {
                return response()->json([
                    'message' => 'Contexto administrativo não é permitido para este perfil.',
                ], 403);
            }

            $request->attributes->set('effective_company_id', $user?->company_id);

            return $next($request);
        }

        if (! $request->isMethod('GET')) {
            return response()->json([
                'message' => 'O contexto administrativo permite somente consultas.',
            ], 403);
        }

        if (! in_array($request->route()?->getName(), self::PLATFORM_READ_ROUTE_ALLOWLIST, true)) {
            return response()->json([
                'message' => 'Endpoint não permitido no contexto administrativo.',
            ], 403);
        }

        if ($plainContext === '') {
            return response()->json([
                'message' => 'Contexto de clínica ausente.',
            ], 403);
        }

        $context = $this->contextService->resolveActive($user, $plainContext);
        if (! $context) {
            return response()->json([
                'message' => 'Contexto de clínica inválido, expirado ou revogado.',
            ], 403);
        }

        $this->contextService->touch($context);
        $request->attributes->set('effective_company_id', $context->company_id);
        $request->attributes->set('platform_tenant_view_context', $context);

        return $next($request);
    }
}
