<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EffectiveTenantContext
{
    public function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('effective_company_id', $request->user()?->company_id);

        if (! $companyId) {
            throw new AccessDeniedHttpException('Contexto de empresa não resolvido.');
        }

        return (int) $companyId;
    }

    public function isPlatformView(Request $request): bool
    {
        return $request->attributes->get('platform_tenant_view_context') !== null;
    }
}
