<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LeadSourceClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_gestor_can_classify_lead_source_manually(): void
    {
        $company = Company::create([
            'name' => 'Empresa Teste',
            'slug' => 'empresa-teste',
            'timezone' => 'America/Sao_Paulo',
        ]);

        $gestor = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor',
            'email' => 'gestor@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead 1',
            'phone_e164' => '+5511999990000',
            'source' => 'desconhecido',
            'source_method' => 'auto',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->patchJson('/api/v1/leads/' . $lead->id . '/source', [
            'source' => 'google',
            'reason' => 'Informado pelo gestor',
        ]);

        $response->assertOk()
            ->assertJsonPath('lead.source', 'google')
            ->assertJsonPath('lead.source_method', 'manual');
    }

    public function test_admin_can_classify_lead_source_manually(): void
    {
        $company = Company::create([
            'name' => 'Empresa Admin',
            'slug' => 'empresa-admin',
        ]);

        $admin = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'admin',
            'active' => true,
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead Admin',
            'phone_e164' => '+5511999990004',
            'source' => 'desconhecido',
            'source_method' => 'auto',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => '12345678',
        ])->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->patchJson('/api/v1/leads/' . $lead->id . '/source', [
            'source' => 'instagram',
        ])->assertOk();
    }

    public function test_sdr_cannot_classify_lead_source(): void
    {
        $company = Company::create([
            'name' => 'Empresa Teste',
            'slug' => 'empresa-teste',
            'timezone' => 'America/Sao_Paulo',
        ]);

        $sdr = User::create([
            'company_id' => $company->id,
            'name' => 'SDR',
            'email' => 'sdr@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'sdr',
            'active' => true,
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead 2',
            'phone_e164' => '+5511999990001',
            'source' => 'desconhecido',
            'source_method' => 'auto',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $sdr->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->patchJson('/api/v1/leads/' . $lead->id . '/source', [
            'source' => 'google',
        ]);

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_access_source_endpoints(): void
    {
        $this->getJson('/api/v1/leads/sources/unknown')->assertUnauthorized();
        $this->getJson('/api/v1/leads/sources/recent')->assertUnauthorized();
        $this->patchJson('/api/v1/leads/1/source', ['source' => 'google'])->assertUnauthorized();
    }

    public function test_user_cannot_classify_lead_from_another_company(): void
    {
        $companyA = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $companyB = Company::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);

        $gestorA = User::create([
            'company_id' => $companyA->id,
            'name' => 'Gestor A',
            'email' => 'gestor.a@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $leadB = Lead::create([
            'company_id' => $companyB->id,
            'name' => 'Lead B',
            'phone_e164' => '+5511999990005',
            'source' => 'desconhecido',
            'source_method' => 'auto',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestorA->email,
            'password' => '12345678',
        ])->json('token');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/leads/' . $leadB->id . '/source', ['source' => 'google'])
            ->assertNotFound();
    }

    public function test_source_endpoints_only_return_company_leads(): void
    {
        $companyA = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $companyB = Company::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);

        $gestorA = User::create([
            'company_id' => $companyA->id,
            'name' => 'Gestor A',
            'email' => 'gestor.a2@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $leadAUnknown = Lead::create([
            'company_id' => $companyA->id,
            'name' => 'Lead A Unknown',
            'phone_e164' => '+5511999990006',
            'source' => 'desconhecido',
            'source_method' => 'auto',
        ]);

        Lead::create([
            'company_id' => $companyB->id,
            'name' => 'Lead B Unknown',
            'phone_e164' => '+5511999990007',
            'source' => 'desconhecido',
            'source_method' => 'auto',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestorA->email,
            'password' => '12345678',
        ])->json('token');

        $unknown = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/leads/sources/unknown')
            ->assertOk()
            ->json('data');

        $recent = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/leads/sources/recent')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $unknown);
        $this->assertSame($leadAUnknown->id, $unknown[0]['id']);
        $this->assertCount(1, $recent);
        $this->assertSame($leadAUnknown->id, $recent[0]['id']);
    }
}
