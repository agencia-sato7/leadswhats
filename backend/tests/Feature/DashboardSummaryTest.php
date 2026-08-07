<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSourceHistory;
use App\Models\Message;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summary_preserves_contract_and_isolates_company_data(): void
    {
        Carbon::setTestNow("2026-05-06 15:00:00");

        $companyA = Company::create([
            "name" => "Empresa A",
            "slug" => "empresa-a",
            "timezone" => "America/Sao_Paulo",
        ]);

        $companyB = Company::create([
            "name" => "Empresa B",
            "slug" => "empresa-b",
            "timezone" => "America/Sao_Paulo",
        ]);

        $gestorA = User::create([
            "company_id" => $companyA->id,
            "name" => "Gestor A",
            "email" => "gestor.a@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $leadA1 = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead A1",
            "phone_e164" => "+5511955550001",
            "source" => "desconhecido",
            "is_repeat_lead" => false,
            "first_response_seconds" => 120,
            "last_inbound_at" => Carbon::parse("2026-05-06 09:00:00"),
        ]);

        $leadA2 = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead A2",
            "phone_e164" => "+5511955550002",
            "source" => "google",
            "is_repeat_lead" => true,
            "first_response_seconds" => 180,
            "last_inbound_at" => Carbon::parse("2026-05-06 09:10:00"),
        ]);

        $leadA3 = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead A3",
            "phone_e164" => "+5511955550003",
            "source" => "facebook",
            "is_repeat_lead" => false,
            "last_inbound_at" => Carbon::parse("2026-05-05 10:00:00"),
            "last_outbound_at" => Carbon::parse("2026-05-05 14:30:00"),
        ]);

        $leadB = Lead::create([
            "company_id" => $companyB->id,
            "name" => "Lead B",
            "phone_e164" => "+5511955550999",
            "source" => "desconhecido",
            "is_repeat_lead" => true,
            "first_response_seconds" => 999,
            "last_inbound_at" => Carbon::parse("2026-05-05 10:00:00"),
            "last_outbound_at" => Carbon::parse("2026-05-05 10:30:00"),
        ]);

        $conversationA = Conversation::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadA1->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-06 09:00:00"),
        ]);

        $conversationB = Conversation::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadB->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-06 10:00:00"),
        ]);

        Message::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadA1->id,
            "conversation_id" => $conversationA->id,
            "provider" => "whatsapp",
            "direction" => "outbound",
            "channel" => "text",
            "sent_at" => Carbon::parse("2026-05-06 12:00:00"),
            "is_rescue" => true,
        ]);

        Message::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadB->id,
            "conversation_id" => $conversationB->id,
            "provider" => "whatsapp",
            "direction" => "outbound",
            "channel" => "text",
            "sent_at" => Carbon::parse("2026-05-06 12:00:00"),
            "is_rescue" => true,
        ]);

        LeadSourceHistory::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadA1->id,
            "previous_source" => "desconhecido",
            "new_source" => "instagram",
            "change_type" => "manual",
            "changed_at" => Carbon::parse("2026-05-06 13:00:00"),
        ]);

        LeadSourceHistory::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadB->id,
            "previous_source" => "desconhecido",
            "new_source" => "google",
            "change_type" => "manual",
            "changed_at" => Carbon::parse("2026-05-06 13:00:00"),
        ]);

        $login = $this->postJson("/api/v1/auth/login", [
            "email" => $gestorA->email,
            "password" => "12345678",
        ]);

        $token = $login->json("token");

        $response = $this->withHeaders([
            "Authorization" => "Bearer " . $token,
        ])->getJson("/api/v1/dashboard/summary");

        $response->assertOk()
            ->assertJsonStructure([
                "date",
                "metrics" => [
                    "new_leads_today",
                    "repeat_leads_today",
                    "avg_first_response_seconds",
                    "vacuum_24h_open",
                    "rescues_today",
                    "active_conversations",
                    "unknown_source_leads",
                "manual_classifications_today",
                "open_tasks",
                "vacuum_follow_up_tasks",
                "waiting_first_response_tasks",
                "overdue_follow_up_tasks",
                "unassigned_leads",
                "oldest_pending_task_hours",
            ],
        ])
            ->assertJsonPath("date", "2026-05-06")
            ->assertJsonPath("metrics.new_leads_today", 2)
            ->assertJsonPath("metrics.repeat_leads_today", 1)
            ->assertJsonPath("metrics.avg_first_response_seconds", 150)
            ->assertJsonPath("metrics.vacuum_24h_open", 1)
            ->assertJsonPath("metrics.rescues_today", 1)
            ->assertJsonPath("metrics.active_conversations", 1)
            ->assertJsonPath("metrics.unknown_source_leads", 1)
            ->assertJsonPath("metrics.manual_classifications_today", 1)
            ->assertJsonPath("metrics.open_tasks", 0)
            ->assertJsonPath("metrics.vacuum_follow_up_tasks", 0)
            ->assertJsonPath("metrics.waiting_first_response_tasks", 0)
            ->assertJsonPath("metrics.overdue_follow_up_tasks", 0)
            ->assertJsonPath("metrics.unassigned_leads", 3)
            ->assertJsonPath("metrics.oldest_pending_task_hours", 0);

        Carbon::setTestNow();
    }

    public function test_dashboard_summary_counts_operational_checklist_tasks(): void
    {
        Carbon::setTestNow("2026-05-07 12:00:00");

        $companyA = Company::create([
            "name" => "Empresa A",
            "slug" => "empresa-a",
            "timezone" => "America/Sao_Paulo",
        ]);

        $companyB = Company::create([
            "name" => "Empresa B",
            "slug" => "empresa-b",
            "timezone" => "America/Sao_Paulo",
        ]);

        CompanyBusinessSetting::create([
            "company_id" => $companyA->id,
            "timezone" => "America/Sao_Paulo",
            "workday_start_time" => "08:00:00",
            "workday_end_time" => "18:00:00",
            "lunch_start_time" => "12:00:00",
            "lunch_end_time" => "13:00:00",
            "working_days" => [1, 2, 3, 4, 5],
            "repeated_lead_window_days" => 90,
            "rescue_threshold_hours" => 12,
            "webhook_token" => null,
        ]);

        $gestorA = User::create([
            "company_id" => $companyA->id,
            "name" => "Gestor A",
            "email" => "gestor.tasks.dashboard@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $leadA = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead A",
            "phone_e164" => "+5511955552001",
            "source" => "google",
            "owner_user_id" => null,
        ]);

        $conversationA = Conversation::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadA->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-06 09:00:00"),
            "last_message_at" => Carbon::parse("2026-05-06 22:00:00"),
        ]);

        Message::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadA->id,
            "conversation_id" => $conversationA->id,
            "provider" => "whatsapp",
            "direction" => "outbound",
            "channel" => "text",
            "sent_at" => Carbon::parse("2026-05-06 22:00:00"),
            "is_rescue" => false,
        ]);

        $leadWaiting = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead waiting",
            "phone_e164" => "+5511955552002",
            "source" => "instagram",
            "owner_user_id" => null,
        ]);

        $conversationWaiting = Conversation::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadWaiting->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-07 10:00:00"),
            "last_message_at" => Carbon::parse("2026-05-07 11:30:00"),
        ]);

        Message::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadWaiting->id,
            "conversation_id" => $conversationWaiting->id,
            "provider" => "whatsapp",
            "direction" => "inbound",
            "channel" => "text",
            "sent_at" => Carbon::parse("2026-05-07 11:30:00"),
            "is_rescue" => false,
        ]);

        $leadB = Lead::create([
            "company_id" => $companyB->id,
            "name" => "Lead B",
            "phone_e164" => "+5511955552999",
            "source" => "google",
        ]);

        $conversationB = Conversation::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadB->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-06 09:00:00"),
            "last_message_at" => Carbon::parse("2026-05-06 20:00:00"),
        ]);

        Message::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadB->id,
            "conversation_id" => $conversationB->id,
            "provider" => "whatsapp",
            "direction" => "outbound",
            "channel" => "text",
            "sent_at" => Carbon::parse("2026-05-06 20:00:00"),
            "is_rescue" => false,
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $gestorA->email,
            "password" => "12345678",
        ])->json("token");

        $response = $this->withHeaders([
            "Authorization" => "Bearer " . $token,
        ])->getJson("/api/v1/dashboard/summary");

        $response->assertOk()
            ->assertJsonPath("metrics.open_tasks", 2)
            ->assertJsonPath("metrics.vacuum_follow_up_tasks", 1)
            ->assertJsonPath("metrics.waiting_first_response_tasks", 1)
            ->assertJsonPath("metrics.overdue_follow_up_tasks", 1)
            ->assertJsonPath("metrics.unassigned_leads", 2)
            ->assertJsonPath("metrics.oldest_pending_task_hours", 14);

        Carbon::setTestNow();
    }

    public function test_dashboard_summary_calculates_effectiveness_and_funnel_by_source(): void
    {
        Carbon::setTestNow("2026-05-10 12:00:00");

        $company = Company::create([
            "name" => "Company S",
            "slug" => "company-s",
            "timezone" => "America/Sao_Paulo",
        ]);

        $user = User::create([
            "company_id" => $company->id,
            "name" => "Gestor S",
            "email" => "gestor.s@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        // Pipeline e colunas
        $pipeline = \App\Models\Pipeline::create([
            "company_id" => $company->id,
            "name" => "Vendas",
            "is_default" => true,
        ]);

        $column1 = \App\Models\KanbanColumn::create([
            "company_id" => $company->id,
            "pipeline_id" => $pipeline->id,
            "name" => "Novo Lead",
            "position" => 1,
        ]);

        $column2 = \App\Models\KanbanColumn::create([
            "company_id" => $company->id,
            "pipeline_id" => $pipeline->id,
            "name" => "Qualificado",
            "position" => 2,
        ]);

        // Lead 1: Sucesso (Inbound -> Outbound -> Inbound)
        $lead1 = Lead::create([
            "company_id" => $company->id,
            "name" => "Lead 1",
            "phone_e164" => "+5511999990001",
            "source" => "instagram",
        ]);

        $conv1 = Conversation::create([
            "company_id" => $company->id,
            "lead_id" => $lead1->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-10 09:00:00"),
        ]);

        Message::create([
            "company_id" => $company->id,
            "lead_id" => $lead1->id,
            "conversation_id" => $conv1->id,
            "direction" => "inbound",
            "sent_at" => Carbon::parse("2026-05-10 09:00:00"),
        ]);

        Message::create([
            "company_id" => $company->id,
            "lead_id" => $lead1->id,
            "conversation_id" => $conv1->id,
            "direction" => "outbound",
            "sent_at" => Carbon::parse("2026-05-10 09:05:00"),
        ]);

        Message::create([
            "company_id" => $company->id,
            "lead_id" => $lead1->id,
            "conversation_id" => $conv1->id,
            "direction" => "inbound",
            "sent_at" => Carbon::parse("2026-05-10 09:10:00"),
        ]);

        // Registrar estágio para Lead 1
        \App\Models\LeadStageHistory::create([
            "company_id" => $company->id,
            "lead_id" => $lead1->id,
            "to_column_id" => $column2->id,
            "moved_at" => Carbon::parse("2026-05-10 09:15:00"),
        ]);

        // Lead 2: Perdido (Outbound > 24h sem resposta)
        $lead2 = Lead::create([
            "company_id" => $company->id,
            "name" => "Lead 2",
            "phone_e164" => "+5511999990002",
            "source" => "google",
        ]);

        $conv2 = Conversation::create([
            "company_id" => $company->id,
            "lead_id" => $lead2->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-08 09:00:00"),
        ]);

        Message::create([
            "company_id" => $company->id,
            "lead_id" => $lead2->id,
            "conversation_id" => $conv2->id,
            "direction" => "outbound",
            "sent_at" => Carbon::parse("2026-05-08 09:00:00"),
        ]);

        // Registrar estágio para Lead 2
        \App\Models\LeadStageHistory::create([
            "company_id" => $company->id,
            "lead_id" => $lead2->id,
            "to_column_id" => $column1->id,
            "moved_at" => Carbon::parse("2026-05-08 09:00:00"),
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $user->email,
            "password" => "12345678",
        ])->json("token");

        $response = $this->withHeaders([
            "Authorization" => "Bearer " . $token,
        ])->getJson("/api/v1/dashboard/summary");

        $response->assertOk()
            ->assertJsonPath("metrics.successful_conversations_today", 1)
            ->assertJsonPath("metrics.lost_conversations_today", 1)
            ->assertJsonPath("metrics.effectiveness_percentage", 50)
            ->assertJsonStructure([
                "funnel_by_source" => [
                    "*" => ["source", "stage_name", "count"]
                ]
            ]);

        $funnelData = $response->json("funnel_by_source");
        $this->assertCount(2, $funnelData);

        Carbon::setTestNow();
    }

    public function test_sdr_only_sees_own_performance_while_gestor_sees_company_totals(): void
    {
        Carbon::setTestNow("2026-05-11 12:00:00");

        $company = Company::create([
            "name" => "Empresa Privacidade",
            "slug" => "empresa-privacidade",
            "timezone" => "America/Sao_Paulo",
        ]);

        $gestor = User::create([
            "company_id" => $company->id,
            "name" => "Gestor",
            "email" => "gestor.privacidade@test.local",
            "password" => Hash::make("12345678"),
            "role" => "gestor",
            "active" => true,
        ]);

        $sdrA = User::create([
            "company_id" => $company->id,
            "name" => "SDR A",
            "email" => "sdr.a.privacidade@test.local",
            "password" => Hash::make("12345678"),
            "role" => "sdr",
            "active" => true,
        ]);

        $sdrB = User::create([
            "company_id" => $company->id,
            "name" => "SDR B",
            "email" => "sdr.b.privacidade@test.local",
            "password" => Hash::make("12345678"),
            "role" => "sdr",
            "active" => true,
        ]);

        $leadOwnedByA = Lead::create([
            "company_id" => $company->id,
            "owner_user_id" => $sdrA->id,
            "name" => "Lead da SDR A",
            "phone_e164" => "+5511955553001",
            "source" => "google",
            "is_repeat_lead" => false,
        ]);

        $leadOwnedByB = Lead::create([
            "company_id" => $company->id,
            "owner_user_id" => $sdrB->id,
            "name" => "Lead da SDR B",
            "phone_e164" => "+5511955553002",
            "source" => "google",
            "is_repeat_lead" => false,
        ]);

        $unassignedLead = Lead::create([
            "company_id" => $company->id,
            "owner_user_id" => null,
            "name" => "Lead sem dono",
            "phone_e164" => "+5511955553003",
            "source" => "desconhecido",
            "is_repeat_lead" => false,
        ]);

        $conversationOwnedByA = Conversation::create([
            "company_id" => $company->id,
            "lead_id" => $leadOwnedByA->id,
            "owner_user_id" => $sdrA->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-11 09:00:00"),
        ]);

        Conversation::create([
            "company_id" => $company->id,
            "lead_id" => $leadOwnedByB->id,
            "owner_user_id" => $sdrB->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-11 09:00:00"),
        ]);

        $token = $this->postJson("/api/v1/auth/login", [
            "email" => $sdrA->email,
            "password" => "12345678",
        ])->json("token");

        $sdrResponse = $this->withHeaders([
            "Authorization" => "Bearer " . $token,
        ])->getJson("/api/v1/dashboard/summary");

        $sdrResponse->assertOk()
            ->assertJsonPath("metrics.new_leads_today", 1)
            ->assertJsonPath("metrics.active_conversations", 1)
            ->assertJsonPath("metrics.unassigned_leads", 0);

        $gestorToken = $this->postJson("/api/v1/auth/login", [
            "email" => $gestor->email,
            "password" => "12345678",
        ])->json("token");

        $gestorResponse = $this->withHeaders([
            "Authorization" => "Bearer " . $gestorToken,
        ])->getJson("/api/v1/dashboard/summary");

        $gestorResponse->assertOk()
            ->assertJsonPath("metrics.new_leads_today", 3)
            ->assertJsonPath("metrics.active_conversations", 2)
            ->assertJsonPath("metrics.unassigned_leads", 1);

        Carbon::setTestNow();
    }
}
