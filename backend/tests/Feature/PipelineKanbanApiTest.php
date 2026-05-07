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

    public function test_unauthenticated_user_gets_401_when_moving_lead_stage(): void
    {
        $this->patchJson("/api/v1/leads/999999/stage", [
            "kanban_column_id" => 1,
            "reason" => "Movido manualmente pelo gestor",
        ])->assertUnauthorized();
    }

    public function test_gestor_can_move_lead_stage_with_history_and_kanban_reflection(): void
    {
        $company = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);

        $gestor = User::create([
            "company_id" => $company->id,
            "name" => "Gestor A",
            "email" => "gestor.stage.move@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $pipeline = Pipeline::create([
            "company_id" => $company->id,
            "name" => "Pipeline Comercial",
            "is_default" => true,
        ]);

        $columnOne = KanbanColumn::create([
            "company_id" => $company->id,
            "pipeline_id" => $pipeline->id,
            "name" => "Novo Contato",
            "position" => 1,
        ]);

        $columnTwo = KanbanColumn::create([
            "company_id" => $company->id,
            "pipeline_id" => $pipeline->id,
            "name" => "Negociação",
            "position" => 2,
        ]);

        $lead = Lead::create([
            "company_id" => $company->id,
            "name" => "Lead Movimentação",
            "phone_e164" => "+5511991111111",
            "source" => "instagram",
        ]);

        LeadStageHistory::create([
            "company_id" => $company->id,
            "lead_id" => $lead->id,
            "from_column_id" => null,
            "to_column_id" => $columnOne->id,
            "moved_by_user_id" => $gestor->id,
            "move_source" => "manual",
            "reason" => "Entrada inicial",
            "moved_at" => Carbon::parse("2026-05-06 09:00:00"),
        ]);

        Conversation::create([
            "company_id" => $company->id,
            "lead_id" => $lead->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-06 09:00:00"),
            "last_message_at" => Carbon::parse("2026-05-06 09:30:00"),
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $gestor->email,
            "password" => "12345678",
        ])->json("token");

        $moveResponse = $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->patchJson("/api/v1/leads/" . $lead->id . "/stage", [
                "kanban_column_id" => $columnTwo->id,
                "reason" => "Movido manualmente pelo gestor",
            ]);

        $moveResponse->assertOk()
            ->assertJsonPath("message", "Lead stage updated successfully.")
            ->assertJsonPath("data.lead_id", $lead->id)
            ->assertJsonPath("data.kanban_column_id", $columnTwo->id)
            ->assertJsonPath("data.moved_by_user_id", $gestor->id)
            ->assertJsonPath("data.movement_type", "manual")
            ->assertJsonPath("data.history_created", true);

        $this->assertDatabaseHas("lead_stage_histories", [
            "company_id" => $company->id,
            "lead_id" => $lead->id,
            "from_column_id" => $columnOne->id,
            "to_column_id" => $columnTwo->id,
            "moved_by_user_id" => $gestor->id,
            "move_source" => "manual",
            "reason" => "Movido manualmente pelo gestor",
        ]);

        $historyResponse = $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/leads/" . $lead->id . "/stage-history");

        $historyResponse->assertOk()
            ->assertJsonPath("data.0.lead_id", $lead->id)
            ->assertJsonPath("data.0.from_column_id", $columnOne->id)
            ->assertJsonPath("data.0.to_column_id", $columnTwo->id);

        $kanbanResponse = $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/pipelines/" . $pipeline->id . "/kanban");

        $kanbanResponse->assertOk()
            ->assertJsonCount(0, "data.columns.0.cards")
            ->assertJsonCount(1, "data.columns.1.cards")
            ->assertJsonPath("data.columns.1.cards.0.lead_id", $lead->id);
    }

    public function test_admin_can_move_lead_stage_successfully(): void
    {
        $company = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);

        $admin = User::create([
            "company_id" => $company->id,
            "name" => "Admin A",
            "email" => "admin.stage.move@test.local",
            "password" => Hash::make("12345678"),
            "role" => "admin",
            "active" => true,
        ]);

        $pipeline = Pipeline::create([
            "company_id" => $company->id,
            "name" => "Pipeline Comercial",
            "is_default" => true,
        ]);

        $column = KanbanColumn::create([
            "company_id" => $company->id,
            "pipeline_id" => $pipeline->id,
            "name" => "Novo Contato",
            "position" => 1,
        ]);

        $lead = Lead::create([
            "company_id" => $company->id,
            "name" => "Lead Admin",
            "phone_e164" => "+5511992222222",
            "source" => "google",
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $admin->email,
            "password" => "12345678",
        ])->json("token");

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->patchJson("/api/v1/leads/" . $lead->id . "/stage", [
                "kanban_column_id" => $column->id,
                "reason" => "Movido manualmente pelo gestor",
            ])
            ->assertOk()
            ->assertJsonPath("data.lead_id", $lead->id)
            ->assertJsonPath("data.kanban_column_id", $column->id);
    }

    public function test_sdr_cannot_move_lead_stage(): void
    {
        $company = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);

        $sdr = User::create([
            "company_id" => $company->id,
            "name" => "SDR A",
            "email" => "sdr.stage.move@test.local",
            "password" => Hash::make("12345678"),
            "role" => "sdr",
            "active" => true,
        ]);

        $pipeline = Pipeline::create([
            "company_id" => $company->id,
            "name" => "Pipeline Comercial",
            "is_default" => true,
        ]);

        $column = KanbanColumn::create([
            "company_id" => $company->id,
            "pipeline_id" => $pipeline->id,
            "name" => "Novo Contato",
            "position" => 1,
        ]);

        $lead = Lead::create([
            "company_id" => $company->id,
            "name" => "Lead SDR",
            "phone_e164" => "+5511993333333",
            "source" => "site",
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $sdr->email,
            "password" => "12345678",
        ])->json("token");

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->patchJson("/api/v1/leads/" . $lead->id . "/stage", [
                "kanban_column_id" => $column->id,
                "reason" => "Sem permissão",
            ])
            ->assertForbidden();
    }

    public function test_user_cannot_move_lead_from_another_company(): void
    {
        $companyA = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);
        $companyB = Company::create(["name" => "Empresa B", "slug" => "empresa-b"]);

        $gestorA = User::create([
            "company_id" => $companyA->id,
            "name" => "Gestor A",
            "email" => "gestor.stage.other.tenant@test.local",
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
            "name" => "Coluna A",
            "position" => 1,
        ]);

        $leadB = Lead::create([
            "company_id" => $companyB->id,
            "name" => "Lead B",
            "phone_e164" => "+5511994444444",
            "source" => "google",
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $gestorA->email,
            "password" => "12345678",
        ])->json("token");

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->patchJson("/api/v1/leads/" . $leadB->id . "/stage", [
                "kanban_column_id" => $columnA->id,
                "reason" => "Movimento indevido",
            ])
            ->assertNotFound();
    }

    public function test_user_cannot_move_lead_to_column_from_another_company(): void
    {
        $companyA = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);
        $companyB = Company::create(["name" => "Empresa B", "slug" => "empresa-b"]);

        $gestorA = User::create([
            "company_id" => $companyA->id,
            "name" => "Gestor A",
            "email" => "gestor.stage.column.other.tenant@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $pipelineA = Pipeline::create([
            "company_id" => $companyA->id,
            "name" => "Pipeline A",
            "is_default" => true,
        ]);

        $pipelineB = Pipeline::create([
            "company_id" => $companyB->id,
            "name" => "Pipeline B",
            "is_default" => true,
        ]);

        $columnB = KanbanColumn::create([
            "company_id" => $companyB->id,
            "pipeline_id" => $pipelineB->id,
            "name" => "Coluna B",
            "position" => 1,
        ]);

        $leadA = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead A",
            "phone_e164" => "+5511995555555",
            "source" => "google",
        ]);

        // Keep an in-tenant column to ensure tenant A has a valid pipeline context.
        KanbanColumn::create([
            "company_id" => $companyA->id,
            "pipeline_id" => $pipelineA->id,
            "name" => "Coluna A",
            "position" => 1,
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $gestorA->email,
            "password" => "12345678",
        ])->json("token");

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->patchJson("/api/v1/leads/" . $leadA->id . "/stage", [
                "kanban_column_id" => $columnB->id,
                "reason" => "Movimento indevido",
            ])
            ->assertNotFound();
    }

    public function test_repeating_same_movement_does_not_create_duplicate_history(): void
    {
        $company = Company::create(["name" => "Empresa A", "slug" => "empresa-a"]);

        $gestor = User::create([
            "company_id" => $company->id,
            "name" => "Gestor A",
            "email" => "gestor.stage.idempotent@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $pipeline = Pipeline::create([
            "company_id" => $company->id,
            "name" => "Pipeline A",
            "is_default" => true,
        ]);

        $column = KanbanColumn::create([
            "company_id" => $company->id,
            "pipeline_id" => $pipeline->id,
            "name" => "Novo Contato",
            "position" => 1,
        ]);

        $lead = Lead::create([
            "company_id" => $company->id,
            "name" => "Lead Idempotente",
            "phone_e164" => "+5511996666666",
            "source" => "instagram",
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $gestor->email,
            "password" => "12345678",
        ])->json("token");

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->patchJson("/api/v1/leads/" . $lead->id . "/stage", [
                "kanban_column_id" => $column->id,
                "reason" => "Primeira movimentação",
            ])
            ->assertOk()
            ->assertJsonPath("data.history_created", true);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->patchJson("/api/v1/leads/" . $lead->id . "/stage", [
                "kanban_column_id" => $column->id,
                "reason" => "Repetição",
            ])
            ->assertOk()
            ->assertJsonPath("data.history_created", false);

        $this->assertSame(
            1,
            LeadStageHistory::query()
                ->where("company_id", $company->id)
                ->where("lead_id", $lead->id)
                ->count()
        );
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
