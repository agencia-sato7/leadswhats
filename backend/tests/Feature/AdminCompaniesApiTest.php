<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\KanbanColumn;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCompaniesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_list_companies(): void
    {
        $platformAdmin = $this->createPlatformAdmin('platform.list@test.local');
        $company = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'workday_start_time' => '08:00:00',
            'workday_end_time' => '18:00:00',
            'working_days' => [1, 2, 3, 4, 5],
            'repeated_lead_window_days' => 90,
            'rescue_threshold_hours' => 24,
            'first_response_sla_minutes' => 15,
            'follow_up_sla_hours' => 24,
            'stale_conversation_hours' => 48,
            'webhook_token' => 'demo_token_a',
        ]);
        Pipeline::create(['company_id' => $company->id, 'name' => 'Pipeline A', 'is_default' => true]);
        $this->createTenantUser($company->id, 'admin', 'admin.empresa.a@test.local');

        $token = $this->login($platformAdmin->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/admin/companies')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'users_count', 'pipelines_count', 'has_business_settings', 'created_at'],
                ],
            ])
            ->assertJsonFragment([
                'id' => $company->id,
                'name' => 'Empresa A',
                'slug' => 'empresa-a',
                'users_count' => 1,
                'pipelines_count' => 1,
                'has_business_settings' => true,
            ]);
    }

    public function test_admin_gestor_and_sdr_cannot_list_or_create_admin_companies(): void
    {
        $company = Company::create(['name' => 'Empresa Tenant', 'slug' => 'empresa-tenant-admin-api']);

        foreach (['admin', 'gestor', 'sdr'] as $role) {
            $user = $this->createTenantUser($company->id, $role, $role . '.admin.companies@test.local');
            $token = $this->login($user->email);

            $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->getJson('/api/v1/admin/companies')
                ->assertForbidden();

            $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->postJson('/api/v1/admin/companies', $this->validPayload())
                ->assertForbidden();
        }
    }

    public function test_unauthenticated_user_gets_401_on_admin_companies_endpoints(): void
    {
        $this->getJson('/api/v1/admin/companies')->assertUnauthorized();
        $this->postJson('/api/v1/admin/companies', $this->validPayload())->assertUnauthorized();
    }

    public function test_platform_admin_creates_company_with_defaults_and_masked_webhook_token(): void
    {
        $platformAdmin = $this->createPlatformAdmin('platform.create@test.local');
        $token = $this->login($platformAdmin->email);
        $payload = $this->validPayload();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/admin/companies', $payload)
            ->assertCreated()
            ->assertJsonPath('message', 'Company created successfully.')
            ->assertJsonPath('data.company.name', 'Clínica Exemplo')
            ->assertJsonPath('data.company.slug', 'clinica-exemplo')
            ->assertJsonPath('data.admin_user.role', 'admin')
            ->assertJsonPath('data.settings.webhook_token_configured', true)
            ->assertJsonPath('data.pipeline.columns_count', 5);

        $this->assertDatabaseHas('companies', [
            'slug' => 'clinica-exemplo',
            'name' => 'Clínica Exemplo',
        ]);

        $companyId = (int) $response->json('data.company.id');
        $this->assertDatabaseHas('users', [
            'company_id' => $companyId,
            'email' => 'dono@cliente.com',
            'role' => 'admin',
            'active' => true,
        ]);
        $this->assertDatabaseHas('company_business_settings', [
            'company_id' => $companyId,
            'timezone' => 'America/Sao_Paulo',
        ]);

        $pipeline = Pipeline::query()->where('company_id', $companyId)->where('is_default', true)->first();
        $this->assertNotNull($pipeline);
        $this->assertSame(5, KanbanColumn::query()->where('company_id', $companyId)->where('pipeline_id', $pipeline->id)->count());

        $storedToken = (string) CompanyBusinessSetting::query()->where('company_id', $companyId)->value('webhook_token');
        $this->assertNotSame('', $storedToken);
        $this->assertNotNull($response->json('data.settings.masked_webhook_token'));
        $this->assertNotSame($storedToken, (string) $response->json('data.settings.masked_webhook_token'));
        $this->assertStringStartsWith('****', (string) $response->json('data.settings.masked_webhook_token'));
    }

    public function test_create_company_returns_422_for_duplicate_slug(): void
    {
        $platformAdmin = $this->createPlatformAdmin('platform.slug@test.local');
        $token = $this->login($platformAdmin->email);

        Company::create(['name' => 'Empresa Existente', 'slug' => 'clinica-exemplo']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/admin/companies', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company.slug']);
    }

    public function test_create_company_returns_422_for_duplicate_admin_email(): void
    {
        $platformAdmin = $this->createPlatformAdmin('platform.email@test.local');
        $token = $this->login($platformAdmin->email);
        $otherCompany = Company::create(['name' => 'Empresa Existente', 'slug' => 'empresa-existente-email']);
        $this->createTenantUser($otherCompany->id, 'admin', 'dono@cliente.com');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/admin/companies', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['admin_user.email']);
    }

    public function test_platform_admin_can_view_company_details(): void
    {
        $platformAdmin = $this->createPlatformAdmin('platform.show@test.local');
        $token = $this->login($platformAdmin->email);
        $company = Company::create(['name' => 'Empresa Show', 'slug' => 'empresa-show']);
        $admin = $this->createTenantUser($company->id, 'admin', 'admin.show@test.local');
        $settings = CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'workday_start_time' => '08:00:00',
            'workday_end_time' => '18:00:00',
            'working_days' => [1, 2, 3, 4, 5],
            'repeated_lead_window_days' => 90,
            'rescue_threshold_hours' => 24,
            'first_response_sla_minutes' => 15,
            'follow_up_sla_hours' => 24,
            'stale_conversation_hours' => 48,
            'webhook_token' => 'demo_show_token',
        ]);
        $pipeline = Pipeline::create(['company_id' => $company->id, 'name' => 'Pipeline Show', 'is_default' => true]);
        KanbanColumn::create(['company_id' => $company->id, 'pipeline_id' => $pipeline->id, 'name' => 'Novo Contato', 'position' => 1]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/admin/companies/' . $company->id)
            ->assertOk()
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.admin_users.0.id', $admin->id)
            ->assertJsonPath('data.settings.timezone', $settings->timezone)
            ->assertJsonPath('data.pipeline.id', $pipeline->id)
            ->assertJsonPath('data.pipeline.columns.0.name', 'Novo Contato');
    }

    public function test_platform_admin_can_patch_company_name_and_slug(): void
    {
        $platformAdmin = $this->createPlatformAdmin('platform.patch@test.local');
        $token = $this->login($platformAdmin->email);
        $company = Company::create(['name' => 'Empresa Antiga', 'slug' => 'empresa-antiga']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/admin/companies/' . $company->id, [
                'name' => 'Empresa Nova',
                'slug' => 'empresa-nova',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Company updated successfully.')
            ->assertJsonPath('data.name', 'Empresa Nova')
            ->assertJsonPath('data.slug', 'empresa-nova');

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Empresa Nova',
            'slug' => 'empresa-nova',
        ]);
    }

    private function createPlatformAdmin(string $email): User
    {
        return User::create([
            'company_id' => null,
            'name' => 'Platform Admin',
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => 'platform_admin',
            'active' => true,
        ]);
    }

    private function createTenantUser(int $companyId, string $role, string $email): User
    {
        return User::create([
            'company_id' => $companyId,
            'name' => strtoupper($role),
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => $role,
            'active' => true,
        ]);
    }

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
        ])->json('token');
    }

    /**
     * @return array<string,mixed>
     */
    private function validPayload(): array
    {
        return [
            'company' => [
                'name' => 'Clínica Exemplo',
                'slug' => 'clinica-exemplo',
            ],
            'admin_user' => [
                'name' => 'Dono Cliente',
                'email' => 'dono@cliente.com',
                'password' => 'senha-temporaria',
            ],
            'settings' => [
                'timezone' => 'America/Sao_Paulo',
                'workday_start_time' => '08:00:00',
                'workday_end_time' => '18:00:00',
                'lunch_start_time' => '12:00:00',
                'lunch_end_time' => '13:00:00',
                'working_days' => [1, 2, 3, 4, 5],
                'repeated_lead_window_days' => 90,
                'rescue_threshold_hours' => 24,
                'first_response_sla_minutes' => 15,
                'follow_up_sla_hours' => 24,
                'stale_conversation_hours' => 48,
            ],
        ];
    }
}
