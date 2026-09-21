<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\AccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessControlApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_creates_connector_and_permissions_are_enforced(): void
    {
        [$platform, $company, $profiles] = $this->context();
        $token = $this->login($platform->email);

        $response = $this->withToken($token)->postJson('/api/v1/admin/users', [
            'company_id' => $company->id,
            'access_profile_id' => $profiles['conector-whatsapp']->id,
            'name' => 'Conector Clínica', 'email' => 'conector@test.local', 'password' => 'temporaria-123',
        ])->assertCreated()->assertJsonPath('data.must_change_password', true);

        $userId = (int) $response->json('data.id');
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'conector@test.local', 'password' => 'temporaria-123'])->assertOk();
        $connectorToken = (string) $login->json('token');
        $this->assertSame(['whatsapp_settings.manage', 'whatsapp_settings.view'], $login->json('user.permissions'));

        $this->withToken($connectorToken)->getJson('/api/v1/settings/whatsapp')->assertForbidden();
        $this->withToken($connectorToken)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'temporaria-123', 'password' => 'senha-nova-123', 'password_confirmation' => 'senha-nova-123',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->withToken($connectorToken)->getJson('/api/v1/settings/whatsapp')->assertOk();
        $this->withToken($connectorToken)->getJson('/api/v1/dashboard/summary')->assertForbidden();

        $this->withToken($token)->deleteJson("/api/v1/admin/users/{$userId}")->assertOk();
        $this->assertDatabaseMissing('api_tokens', ['user_id' => $userId]);
        $this->withToken($connectorToken)->getJson('/api/v1/settings/whatsapp')->assertUnauthorized();
        $this->assertDatabaseHas('access_audit_logs', ['event' => 'user.created', 'subject_id' => $userId]);
        $this->assertDatabaseHas('access_audit_logs', ['event' => 'user.deactivated', 'subject_id' => $userId]);
    }

    public function test_profiles_are_tenant_scoped_and_administrator_is_immutable(): void
    {
        [$platform, $company, $profiles] = $this->context();
        $other = Company::query()->create(['name' => 'Outra', 'slug' => 'outra']);
        $otherProfiles = app(AccessControlService::class)->createProfilesForCompany($other);
        $token = $this->login($platform->email);

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'company_id' => $company->id, 'access_profile_id' => $otherProfiles['sdr']->id,
            'name' => 'Inválido', 'email' => 'invalid@test.local', 'password' => '12345678',
        ])->assertUnprocessable()->assertJsonValidationErrors('access_profile_id');

        $this->withToken($token)->patchJson('/api/v1/admin/access-profiles/'.$profiles['administrador']->id, [
            'name' => 'Restrito', 'data_scope' => 'own', 'permissions' => [],
        ])->assertStatus(409);
        $this->withToken($token)->deleteJson('/api/v1/admin/access-profiles/'.$profiles['administrador']->id)->assertStatus(409);
    }

    public function test_custom_own_scope_replaces_legacy_sdr_check(): void
    {
        [$platform, $company] = $this->context();
        $token = $this->login($platform->email);
        $profile = $this->withToken($token)->postJson('/api/v1/admin/access-profiles', [
            'company_id' => $company->id, 'name' => 'Operador próprio', 'description' => 'Teste',
            'data_scope' => 'own', 'permissions' => ['dashboard.view'],
        ])->assertCreated()->json('data');
        $this->assertSame('own', $profile['data_scope']);
        $this->assertDatabaseHas('access_audit_logs', ['event' => 'profile.created', 'company_id' => $company->id]);
    }

    private function context(): array
    {
        $company = Company::query()->create(['name' => 'Clínica A', 'slug' => 'clinica-a']);
        $profiles = app(AccessControlService::class)->createProfilesForCompany($company);
        $platform = User::query()->create([
            'company_id' => null, 'name' => 'Platform', 'email' => 'platform.access@test.local',
            'password' => Hash::make('12345678'), 'role' => 'platform_admin', 'active' => true,
        ]);
        return [$platform, $company, $profiles];
    }

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => '12345678'])->json('token');
    }
}
