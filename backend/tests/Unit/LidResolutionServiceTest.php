<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Domain\LidResolutionService;
use App\Services\Domain\PhoneNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LidResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LidResolutionService
    {
        return new LidResolutionService(new PhoneNormalizationService());
    }

    public function test_updates_lid_lead_phone_when_no_conflicting_lead_exists(): void
    {
        $company = Company::create(["name" => "Empresa Lid", "slug" => "empresa-lid"]);

        $lead = Lead::create([
            "company_id" => $company->id,
            "phone_e164" => "lid:123456789",
            "source" => "whatsapp_qr",
        ]);

        $this->service()->resolve($company, "123456789@lid", "5511988887777");

        $lead->refresh();
        $this->assertSame("+5511988887777", $lead->phone_e164);
        $this->assertSame(1, Lead::where("company_id", $company->id)->count());
    }

    public function test_merges_lid_lead_into_existing_lead_with_the_real_phone(): void
    {
        $company = Company::create(["name" => "Empresa Lid Merge", "slug" => "empresa-lid-merge"]);

        $realLead = Lead::create([
            "company_id" => $company->id,
            "phone_e164" => "+5511988887777",
            "source" => "whatsapp_qr",
            "last_inbound_at" => "2026-08-01 10:00:00",
        ]);
        $realConversation = Conversation::create([
            "company_id" => $company->id,
            "lead_id" => $realLead->id,
            "status" => "active",
            "started_at" => "2026-08-01 10:00:00",
        ]);
        Message::create([
            "company_id" => $company->id,
            "lead_id" => $realLead->id,
            "conversation_id" => $realConversation->id,
            "provider" => "baileys_qr",
            "direction" => "inbound",
            "sent_at" => "2026-08-01 10:00:00",
            "external_message_id" => "REAL1",
        ]);

        $lidLead = Lead::create([
            "company_id" => $company->id,
            "phone_e164" => "lid:123456789",
            "source" => "whatsapp_qr",
            "last_inbound_at" => "2026-08-05 10:00:00",
        ]);
        $lidConversation = Conversation::create([
            "company_id" => $company->id,
            "lead_id" => $lidLead->id,
            "status" => "active",
            "started_at" => "2026-08-05 10:00:00",
        ]);
        Message::create([
            "company_id" => $company->id,
            "lead_id" => $lidLead->id,
            "conversation_id" => $lidConversation->id,
            "provider" => "baileys_qr",
            "direction" => "inbound",
            "sent_at" => "2026-08-05 10:00:00",
            "external_message_id" => "LID1",
        ]);

        $this->service()->resolve($company, "123456789@lid", "5511988887777");

        $this->assertSame(1, Lead::where("company_id", $company->id)->count());
        $this->assertDatabaseMissing("leads", ["id" => $lidLead->id]);

        $realLead->refresh();
        $this->assertSame("+5511988887777", $realLead->phone_e164);
        $this->assertSame("2026-08-05 10:00:00", $realLead->last_inbound_at->format("Y-m-d H:i:s"));

        $this->assertSame(2, Message::where("company_id", $company->id)->count());
        $this->assertDatabaseHas("messages", ["external_message_id" => "REAL1", "lead_id" => $realLead->id]);
        $this->assertDatabaseHas("messages", ["external_message_id" => "LID1", "lead_id" => $realLead->id]);
        $this->assertDatabaseHas("conversations", ["id" => $lidConversation->id, "lead_id" => $realLead->id]);
    }

    public function test_does_nothing_when_no_lid_lead_exists(): void
    {
        $company = Company::create(["name" => "Empresa Sem Lid", "slug" => "empresa-sem-lid"]);

        $this->service()->resolve($company, "123456789@lid", "5511988887777");

        $this->assertSame(0, Lead::where("company_id", $company->id)->count());
    }

    public function test_does_nothing_when_resolved_phone_is_still_not_a_valid_number(): void
    {
        $company = Company::create(["name" => "Empresa Lid Invalido", "slug" => "empresa-lid-invalido"]);

        $lead = Lead::create([
            "company_id" => $company->id,
            "phone_e164" => "lid:123456789",
            "source" => "whatsapp_qr",
        ]);

        $this->service()->resolve($company, "123456789@lid", "123");

        $lead->refresh();
        $this->assertSame("lid:123456789", $lead->phone_e164);
    }
}
