<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssignableUsersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_gestor_lists_assignable_users_from_own_company_with_predictable_order(): void
    {
        $company = $this->createCompany('empresa-assignable-gestor');
        $gestor = $this->createUser($company->id, 'Gestor Logado', 'gestor.assignable@test.local', 'gestor');

        $admin = $this->createUser($company->id, 'Admin A', 'admin.assignable@test.local', 'admin');
        $gestorA = $this->createUser($company->id, 'Gestor A', 'gestor.a.assignable@test.local', 'gestor');
        $sdrA = $this->createUser($company->id, 'SDR A', 'sdr.a.assignable@test.local', 'sdr');
        $this->createUser($company->id, 'Inativo', 'inactive.assignable@test.local', 'sdr', false);

        $token = $this->login($gestor->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/users/assignable')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'email', 'role'],
                ],
            ]);

        $this->assertSame([
            $admin->id,
            $gestorA->id,
            $gestor->id,
            $sdrA->id,
        ], array_column($response->json('data'), 'id'));
    }

    public function test_admin_lists_assignable_users_from_own_company(): void
    {
        $company = $this->createCompany('empresa-assignable-admin');
        $admin = $this->createUser($company->id, 'Admin Logado', 'admin.logged.assignable@test.local', 'admin');
        $gestor = $this->createUser($company->id, 'Gestor B', 'gestor.b.assignable@test.local', 'gestor');
        $sdr = $this->createUser($company->id, 'SDR B', 'sdr.b.assignable@test.local', 'sdr');

        $token = $this->login($admin->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/users/assignable')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $admin->id,
                'name' => 'Admin Logado',
                'email' => 'admin.logged.assignable@test.local',
                'role' => 'admin',
            ])
            ->assertJsonFragment([
                'id' => $gestor->id,
                'name' => 'Gestor B',
                'email' => 'gestor.b.assignable@test.local',
                'role' => 'gestor',
            ])
            ->assertJsonFragment([
                'id' => $sdr->id,
                'name' => 'SDR B',
                'email' => 'sdr.b.assignable@test.local',
                'role' => 'sdr',
            ]);
    }

    public function test_sdr_gets_403_on_assignable_users_endpoint(): void
    {
        $company = $this->createCompany('empresa-assignable-sdr');
        $sdr = $this->createUser($company->id, 'SDR Logado', 'sdr.logged.assignable@test.local', 'sdr');
        $token = $this->login($sdr->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/users/assignable')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_gets_401_on_assignable_users_endpoint(): void
    {
        $this->getJson('/api/v1/users/assignable')
            ->assertUnauthorized();
    }

    public function test_users_from_another_company_do_not_appear(): void
    {
        $companyA = $this->createCompany('empresa-assignable-tenant-a');
        $companyB = $this->createCompany('empresa-assignable-tenant-b');
        $gestorA = $this->createUser($companyA->id, 'Gestor Tenant A', 'gestor.tenant.a.assignable@test.local', 'gestor');
        $visible = $this->createUser($companyA->id, 'SDR Visivel', 'sdr.visible.assignable@test.local', 'sdr');
        $external = $this->createUser($companyB->id, 'SDR Externo', 'sdr.external.assignable@test.local', 'sdr');

        $token = $this->login($gestorA->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/users/assignable')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($external->id, $ids);
    }

    private function createCompany(string $slug): Company
    {
        return Company::create([
            'name' => 'Empresa ' . strtoupper(substr($slug, 0, 6)),
            'slug' => $slug,
        ]);
    }

    private function createUser(int $companyId, string $name, string $email, string $role, bool $active = true): User
    {
        return User::create([
            'company_id' => $companyId,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => $role,
            'active' => $active,
        ]);
    }

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
        ])->json('token');
    }
}
