<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlatformAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_login_and_get_auth_me(): void
    {
        $user = User::create([
            'company_id' => null,
            'name' => 'Platform Admin',
            'email' => 'platform.auth@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'platform_admin',
            'active' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->assertOk();

        $token = (string) $login->json('token');

        $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer ' . $token,
        ])
            ->assertOk()
            ->assertJsonPath('role', 'platform_admin')
            ->assertJsonPath('company_id', null);
    }

    public function test_existing_tenant_roles_still_can_login(): void
    {
        $company = Company::create([
            'name' => 'Empresa Teste Roles',
            'slug' => 'empresa-teste-roles',
        ]);

        foreach (['admin', 'gestor', 'sdr'] as $role) {
            $email = $role . '.auth@test.local';

            User::create([
                'company_id' => $company->id,
                'name' => strtoupper($role),
                'email' => $email,
                'password' => Hash::make('12345678'),
                'role' => $role,
                'active' => true,
            ]);

            $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => '12345678',
            ])
                ->assertOk()
                ->assertJsonPath('user.role', $role)
                ->assertJsonPath('user.company_id', $company->id);
        }
    }

    public function test_platform_admin_cannot_access_tenant_only_endpoint_when_not_explicitly_allowed(): void
    {
        $platformAdmin = User::create([
            'company_id' => null,
            'name' => 'Platform Admin',
            'email' => 'platform.tenant.block@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'platform_admin',
            'active' => true,
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $platformAdmin->email,
            'password' => '12345678',
        ])->json('token');

        $this->getJson('/api/v1/dashboard/summary', [
            'Authorization' => 'Bearer ' . $token,
        ])->assertForbidden();
    }

    public function test_role_middleware_only_allows_platform_admin_when_route_declares_it(): void
    {
        Route::middleware(['auth.token', 'role:platform_admin'])
            ->get('/api/v1/test/platform-admin-only', static fn () => response()->json(['ok' => true]));

        $company = Company::create([
            'name' => 'Empresa Tenant',
            'slug' => 'empresa-tenant-platform-admin-route',
        ]);

        $platformAdmin = User::create([
            'company_id' => null,
            'name' => 'Platform Admin',
            'email' => 'platform.route@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'platform_admin',
            'active' => true,
        ]);

        $tenantAdmin = User::create([
            'company_id' => $company->id,
            'name' => 'Tenant Admin',
            'email' => 'tenant.admin.route@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'admin',
            'active' => true,
        ]);

        $platformToken = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $platformAdmin->email,
            'password' => '12345678',
        ])->json('token');

        $tenantToken = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $tenantAdmin->email,
            'password' => '12345678',
        ])->json('token');

        $this->getJson('/api/v1/test/platform-admin-only', [
            'Authorization' => 'Bearer ' . $platformToken,
        ])->assertOk();

        $this->getJson('/api/v1/test/platform-admin-only', [
            'Authorization' => 'Bearer ' . $tenantToken,
        ])->assertForbidden();
    }
}
