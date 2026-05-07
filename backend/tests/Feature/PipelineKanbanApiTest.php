<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PipelineKanbanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_gets_401_on_pipeline_endpoints(): void
    {
        $pipelineId = 999999;
        $leadId = 999999;

        $this->getJson("/api/v1/pipelines")
            ->assertUnauthorized();

        $this->getJson("/api/v1/pipelines/" . $pipelineId . "/kanban")
            ->assertUnauthorized();

        $this->getJson("/api/v1/leads/" . $leadId . "/stage-history")
            ->assertUnauthorized();
    }

    public function test_gestor_lists_only_company_pipelines(): void
    {
        $companyA = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);
        $companyB = Company::create(["name" => "Empresa B", "slug" => "empresa-b"]);

        $gestorA = User::create([
            "company_id" => $companyA->id,
            "name" => "Gestor A",
            "email" => "gestor.pipeline.a@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $pipelineA = Pipeline::create([
            "company_id" => $companyA->id,
            "name" => "Pipeline A",
            "is_default" => true,
        ]);

        Pipeline::create([
            "company_id" => $companyB->id,
            "name" => "Pipeline B",
            "is_default" => true,
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $gestorA->email,
            "password" => "12345678",
        ])->json("token");

        $response = $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/pipelines");

        $response->assertOk();
        $response->assertJsonCount(1, "data");
        $response->assertJsonPath("data.0.id", $pipelineA->id);
    }

    public function test_user_cannot_access_pipeline_from_another_company(): void
    {
        $companyA = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);
        $companyB = Company::create(["name" => "Empresa B", "slug" => "empresa-b"]);

        $gestorA = User::create([
            "company_id" => $companyA->id,
            "name" => "Gestor A",
            "email" => "gestor.pipeline.notfound@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $pipelineB = Pipeline::create([
            "company_id" => $companyB->id,
            "name" => "Pipeline B",
            "is_default" => true,
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $gestorA->email,
            "password" => "12345678",
        ])->json("token");

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/pipelines/" . $pipelineB->id . "/kanban")
            ->assertNotFound();
    }

    public function test_kanban_returns_ordered_columns_and_isolated_leads(): void
    {
        Carbon::setTestNow("2026-05-06 10:00:00");

        $companyA = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);
        $companyB = Company::create(["name" => "Empresa B", "slug" => "empresa-b"]);

        $gestorA = User::create([
            "company_id" => $companyA->id,
            "name" => "Gestor A",
            "email" => "gestor.pipeline.kanban@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $pipelineA = Pipeline::create([
            "company_id" => $companyA->id,
            "name" => "Pipeline Comercial",
            "is_default" => true,
        ]);

        $pipelineB = Pipeline::create([
            "company_id" => $companyB->id,
            "name" => "Pipeline B",
            "is_default" => true,
        ]);

        $columnA2 = KanbanColumn::create([
            "company_id" => $companyA->id,
            "pipeline_id" => $pipelineA->id,
            "name" => "Negociação",
            "position" => 2,
            "rule_prompt" => "Cliente pediu proposta",
        ]);

        $columnA1 = KanbanColumn::create([
            "company_id" => $companyA->id,
            "pipeline_id" => $pipelineA->id,
            "name" => "Novo Contato",
            "position" => 1,
            "rule_prompt" => null,
        ]);

        $columnB1 = KanbanColumn::create([
            "company_id" => $companyB->id,
            "pipeline_id" => $pipelineB->id,
            "name" => "Outro Tenant",
            "position" => 1,
            "rule_prompt" => null,
        ]);

        $leadA = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Maria",
            "phone_e164" => "+5511999999999",
            "source" => "instagram",
            "is_repeat_lead" => false,
        ]);

        $leadB = Lead::create([
            "company_id" => $companyB->id,
            "name" => "João",
            "phone_e164" => "+5511888888888",
            "source" => "google",
            "is_repeat_lead" => false,
        ]);

        Conversation::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadA->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-06 09:00:00"),
            "last_message_at" => Carbon::parse("2026-05-06 09:30:00"),
        ]);

        Conversation::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadB->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-06 09:00:00"),
            "last_message_at" => Carbon::parse("2026-05-06 09:30:00"),
        ]);

        LeadStageHistory::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadA->id,
            "from_column_id" => null,
            "to_column_id" => $columnA1->id,
            "moved_by_user_id" => $gestorA->id,
            "move_source" => "manual",
            "reason" => "Primeiro contato",
            "moved_at" => Carbon::parse("2026-05-06 09:10:00"),
        ]);

        LeadStageHistory::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadB->id,
            "from_column_id" => null,
            "to_column_id" => $columnB1->id,
            "move_source" => "manual",
            "reason" => null,
            "moved_at" => Carbon::parse("2026-05-06 09:10:00"),
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $gestorA->email,
            "password" => "12345678",
        ])->json("token");

        $response = $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/pipelines/" . $pipelineA->id . "/kanban");

        $response->assertOk()
            ->assertJsonPath("data.id", $pipelineA->id)
            ->assertJsonPath("data.columns.0.id", $columnA1->id)
            ->assertJsonPath("data.columns.1.id", $columnA2->id)
            ->assertJsonCount(1, "data.columns.0.cards")
            ->assertJsonPath("data.columns.0.cards.0.lead_id", $leadA->id)
            ->assertJsonPath("data.columns.0.cards.0.classification", "lead_novo");

        $columnOneCards = $response->json("data.columns.0.cards");
        $this->assertCount(1, $columnOneCards);
        $this->assertSame($leadA->id, $columnOneCards[0]["lead_id"]);

        Carbon::setTestNow();
    }

    public function test_stage_history_does_not_return_other_tenant_data(): void
    {
        $companyA = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);
        $companyB = Company::create(["name" => "Empresa B", "slug" => "empresa-b"]);

        $gestorA = User::create([
            "company_id" => $companyA->id,
            "name" => "Gestor A",
            "email" => "gestor.pipeline.history@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $pipelineA = Pipeline::create([
            "company_id" => $companyA->id,
            "name" => "Pipeline A",
            "is_default" => true,
        ]);

        $columnA = KanbanColumn::create([
            "company_id" => $companyA->id,
            "pipeline_id" => $pipelineA->id,
            "name" => "Etapa A",
            "position" => 1,
        ]);

        $leadA = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead A",
            "phone_e164" => "+5511999991200",
            "source" => "desconhecido",
        ]);

        LeadStageHistory::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadA->id,
            "from_column_id" => null,
            "to_column_id" => $columnA->id,
            "moved_by_user_id" => $gestorA->id,
            "move_source" => "manual",
            "reason" => "Cadastro",
            "moved_at" => Carbon::parse("2026-05-06 10:00:00"),
        ]);

        $leadB = Lead::create([
            "company_id" => $companyB->id,
            "name" => "Lead B",
            "phone_e164" => "+5511999991300",
            "source" => "desconhecido",
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $gestorA->email,
            "password" => "12345678",
        ])->json("token");

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/leads/" . $leadA->id . "/stage-history")
            ->assertOk()
            ->assertJsonCount(1, "data")
            ->assertJsonPath("data.0.lead_id", $leadA->id);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/leads/" . $leadB->id . "/stage-history")
            ->assertNotFound();
    }
}
