<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadSourceHistory;
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
            "name" => "Empresa Teste",
            "slug" => "empresa-teste",
            "timezone" => "America/Sao_Paulo",
        ]);

        $gestor = User::create([
            "company_id" => $company->id,
            "name" => "Gestor",
            "email" => "gestor@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $lead = Lead::create([
            "company_id" => $company->id,
            "name" => "Lead 1",
            "phone_e164" => "+5511999990000",
            "source" => "desconhecido",
            "source_method" => "auto",
        ]);

        $loginResponse = $this->postJson("/api/v1/auth/login", [
            "email" => $gestor->email,
            "password" => "12345678",
        ]);

        $token = $loginResponse->json("token");

        $response = $this->withHeaders([
            "Authorization" => "Bearer " . $token,
        ])->patchJson("/api/v1/leads/" . $lead->id . "/source", [
            "source" => "google",
            "reason" => "Informado pelo gestor",
        ]);

        $response->assertOk()
            ->assertJsonPath("lead.source", "google")
            ->assertJsonPath("lead.source_method", "manual");

        $this->assertDatabaseHas("lead_source_histories", [
            "company_id" => $company->id,
            "lead_id" => $lead->id,
            "previous_source" => "desconhecido",
            "new_source" => "google",
            "change_type" => "manual",
            "changed_by_user_id" => $gestor->id,
        ]);

        $this->assertSame(1, LeadSourceHistory::count());
    }

    public function test_sdr_cannot_classify_lead_source(): void
    {
        $company = Company::create([
            "name" => "Empresa Teste",
            "slug" => "empresa-teste",
            "timezone" => "America/Sao_Paulo",
        ]);

        $sdr = User::create([
            "company_id" => $company->id,
            "name" => "SDR",
            "email" => "sdr@test.local",
            "password" => Hash::make("12345678"),
            "role" => "sdr",
            "active" => true,
        ]);

        $lead = Lead::create([
            "company_id" => $company->id,
            "name" => "Lead 2",
            "phone_e164" => "+5511999990001",
            "source" => "desconhecido",
            "source_method" => "auto",
        ]);

        $loginResponse = $this->postJson("/api/v1/auth/login", [
            "email" => $sdr->email,
            "password" => "12345678",
        ]);

        $token = $loginResponse->json("token");

        $response = $this->withHeaders([
            "Authorization" => "Bearer " . $token,
        ])->patchJson("/api/v1/leads/" . $lead->id . "/source", [
            "source" => "google",
        ]);

        $response->assertForbidden();

        $this->assertSame(0, LeadSourceHistory::count());
    }
}
