<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSourceHistory;
use App\Models\Message;
use App\Services\Domain\DashboardMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_expected_metrics_for_company_and_isolates_other_tenants(): void
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

        $leadNew = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead Novo",
            "phone_e164" => "+5511911111111",
            "source" => "desconhecido",
            "is_repeat_lead" => false,
            "first_response_seconds" => 120,
            "last_inbound_at" => Carbon::parse("2026-05-06 09:00:00"),
        ]);

        $leadRepeat = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead Repetido",
            "phone_e164" => "+5511922222222",
            "source" => "google",
            "is_repeat_lead" => true,
            "first_response_seconds" => 180,
            "last_inbound_at" => Carbon::parse("2026-05-06 09:10:00"),
        ]);

        $leadVacuum = Lead::create([
            "company_id" => $companyA->id,
            "name" => "Lead Vacuo",
            "phone_e164" => "+5511933333333",
            "source" => "facebook",
            "is_repeat_lead" => false,
            "last_inbound_at" => Carbon::parse("2026-05-05 10:00:00"),
            "last_outbound_at" => Carbon::parse("2026-05-05 14:30:00"),
        ]);

        $leadOtherCompany = Lead::create([
            "company_id" => $companyB->id,
            "name" => "Lead Outro Tenant",
            "phone_e164" => "+5511944444444",
            "source" => "desconhecido",
            "is_repeat_lead" => true,
            "first_response_seconds" => 999,
            "last_inbound_at" => Carbon::parse("2026-05-06 11:00:00"),
            "last_outbound_at" => Carbon::parse("2026-05-05 10:00:00"),
        ]);

        $conversationA = Conversation::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadNew->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-06 09:00:00"),
        ]);

        Conversation::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadRepeat->id,
            "status" => "closed",
            "started_at" => Carbon::parse("2026-05-06 09:20:00"),
            "closed_at" => Carbon::parse("2026-05-06 10:00:00"),
        ]);

        $conversationB = Conversation::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadOtherCompany->id,
            "status" => "active",
            "started_at" => Carbon::parse("2026-05-06 10:00:00"),
        ]);

        Message::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadNew->id,
            "conversation_id" => $conversationA->id,
            "provider" => "whatsapp",
            "direction" => "outbound",
            "channel" => "text",
            "sent_at" => Carbon::parse("2026-05-06 12:00:00"),
            "is_rescue" => true,
        ]);

        Message::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadOtherCompany->id,
            "conversation_id" => $conversationB->id,
            "provider" => "whatsapp",
            "direction" => "outbound",
            "channel" => "text",
            "sent_at" => Carbon::parse("2026-05-06 12:00:00"),
            "is_rescue" => true,
        ]);

        LeadSourceHistory::create([
            "company_id" => $companyA->id,
            "lead_id" => $leadNew->id,
            "previous_source" => "desconhecido",
            "new_source" => "instagram",
            "change_type" => "manual",
            "changed_at" => Carbon::parse("2026-05-06 13:00:00"),
        ]);

        LeadSourceHistory::create([
            "company_id" => $companyB->id,
            "lead_id" => $leadOtherCompany->id,
            "previous_source" => "desconhecido",
            "new_source" => "google",
            "change_type" => "manual",
            "changed_at" => Carbon::parse("2026-05-06 13:00:00"),
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

        $service = app(DashboardMetricsService::class);
        $summary = $service->summaryForCompany($companyA->id);

        $this->assertSame("2026-05-06", $summary["date"]);
        $this->assertSame(2, $summary["metrics"]["new_leads_today"]);
        $this->assertSame(1, $summary["metrics"]["repeat_leads_today"]);
        $this->assertSame(150, $summary["metrics"]["avg_first_response_seconds"]);
        $this->assertSame(1, $summary["metrics"]["vacuum_24h_open"]);
        $this->assertSame(1, $summary["metrics"]["rescues_today"]);
        $this->assertSame(1, $summary["metrics"]["active_conversations"]);
        $this->assertSame(1, $summary["metrics"]["unknown_source_leads"]);
        $this->assertSame(1, $summary["metrics"]["manual_classifications_today"]);
        $this->assertSame(0, $summary["metrics"]["open_tasks"]);
        $this->assertSame(0, $summary["metrics"]["vacuum_follow_up_tasks"]);

        Carbon::setTestNow();
    }
}
