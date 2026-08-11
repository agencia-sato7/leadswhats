<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\PlatformTenantViewAudit;
use App\Models\PlatformTenantViewContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class PlatformTenantViewContextService
{
    public const TTL_MINUTES = 15;

    /**
     * @return array{context_token:string,company:array{id:int,name:string,slug:string},read_only:true,expires_at:string}
     */
    public function start(User $actor, int $companyId, ?string $ipAddress, ?string $userAgent): array
    {
        $this->assertPlatformAdmin($actor);

        $company = Company::query()->findOrFail($companyId);
        if (! $company->active) {
            throw ValidationException::withMessages([
                'company_id' => ['A clínica está inativa e não pode ser aberta.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $company, $ipAddress, $userAgent): array {
            $previousContexts = PlatformTenantViewContext::query()
                ->where('platform_admin_user_id', $actor->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->get();

            foreach ($previousContexts as $previousContext) {
                $previousContext->forceFill(['revoked_at' => now()])->save();
                $this->audit($previousContext, 'exited', 'replaced', $ipAddress, $userAgent);
            }

            $plainToken = 'ptvc_'.Str::random(64);
            $context = PlatformTenantViewContext::query()->create([
                'platform_admin_user_id' => $actor->id,
                'company_id' => $company->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            $this->audit($context, 'entered', 'selected_company', $ipAddress, $userAgent);

            return [
                'context_token' => $plainToken,
                'company' => [
                    'id' => (int) $company->id,
                    'name' => (string) $company->name,
                    'slug' => (string) $company->slug,
                ],
                'read_only' => true,
                'expires_at' => $context->expires_at->toISOString(),
            ];
        });
    }

    public function resolveActive(User $actor, string $plainToken): ?PlatformTenantViewContext
    {
        if (! $actor->active || ($actor->role?->value ?? (string) $actor->role) !== UserRole::PLATFORM_ADMIN->value) {
            return null;
        }

        return PlatformTenantViewContext::query()
            ->with('company')
            ->where('platform_admin_user_id', $actor->id)
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->whereHas('company', static fn ($query) => $query->where('active', true))
            ->first();
    }

    public function touch(PlatformTenantViewContext $context): void
    {
        $context->forceFill(['last_used_at' => now()])->save();
    }

    public function revoke(
        User $actor,
        string $plainToken,
        ?string $ipAddress,
        ?string $userAgent,
    ): bool {
        $this->assertPlatformAdmin($actor);

        return DB::transaction(function () use ($actor, $plainToken, $ipAddress, $userAgent): bool {
            $context = PlatformTenantViewContext::query()
                ->where('platform_admin_user_id', $actor->id)
                ->where('token_hash', hash('sha256', $plainToken))
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $context) {
                return false;
            }

            $context->forceFill(['revoked_at' => now()])->save();
            $this->audit($context, 'exited', 'user_exit', $ipAddress, $userAgent);

            return true;
        });
    }

    private function audit(
        PlatformTenantViewContext $context,
        string $event,
        string $reason,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        PlatformTenantViewAudit::query()->create([
            'platform_tenant_view_context_id' => $context->id,
            'platform_admin_user_id' => $context->platform_admin_user_id,
            'company_id' => $context->company_id,
            'event' => $event,
            'reason' => $reason,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent ? Str::limit($userAgent, 1000, '') : null,
            'occurred_at' => now(),
        ]);
    }

    private function assertPlatformAdmin(User $actor): void
    {
        if (! $actor->active || ($actor->role?->value ?? (string) $actor->role) !== UserRole::PLATFORM_ADMIN->value) {
            throw new LogicException('Apenas platform_admin pode criar contexto de visualização.');
        }
    }
}
