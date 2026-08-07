<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaileysWebhookDirectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_received_respects_outbound_direction_from_payload(): void
    {
        $company = Company::create([
            "name" => "Empresa Baileys",
            "slug" => "empresa-baileys",
        ]);

        CompanyWhatsAppIntegration::create([
            "company_id" => $company->id,
            "provider" => "baileys",
            "integration_type" => "qr",
            "status" => "configured",
            "session_status" => "connected",
        ]);

        // Mensagem do cliente (inbound)
        $this->postJson("/api/v1/webhooks/whatsapp/baileys", [
            "company_id" => $company->id,
            "event" => "message_received",
            "data" => [
                "phone" => "+5511988887777",
                "body" => "Oi, quanto custa?",
                "direction" => "inbound",
                "external_message_id" => "MSG1",
                "sent_at" => now()->toISOString(),
            ],
        ])->assertOk();

        // Resposta do atendimento (outbound, enviada pelo próprio WhatsApp do cliente/empresa)
        $this->postJson("/api/v1/webhooks/whatsapp/baileys", [
            "company_id" => $company->id,
            "event" => "message_received",
            "data" => [
                "phone" => "+5511988887777",
                "body" => "Custa R$ 100",
                "direction" => "outbound",
                "external_message_id" => "MSG2",
                "sent_at" => now()->toISOString(),
            ],
        ])->assertOk();

        $this->assertDatabaseHas("messages", [
            "company_id" => $company->id,
            "external_message_id" => "MSG1",
            "direction" => "inbound",
        ]);

        $this->assertDatabaseHas("messages", [
            "company_id" => $company->id,
            "external_message_id" => "MSG2",
            "direction" => "outbound",
        ]);

        $this->assertSame(2, Message::where("company_id", $company->id)->count());
    }

    public function test_message_received_defaults_to_inbound_when_direction_missing(): void
    {
        $company = Company::create([
            "name" => "Empresa Baileys Default",
            "slug" => "empresa-baileys-default",
        ]);

        CompanyWhatsAppIntegration::create([
            "company_id" => $company->id,
            "provider" => "baileys",
            "integration_type" => "qr",
            "status" => "configured",
            "session_status" => "connected",
        ]);

        $this->postJson("/api/v1/webhooks/whatsapp/baileys", [
            "company_id" => $company->id,
            "event" => "message_received",
            "data" => [
                "phone" => "+5511977776666",
                "body" => "Mensagem sem direção explícita",
                "external_message_id" => "MSG3",
                "sent_at" => now()->toISOString(),
            ],
        ])->assertOk();

        $this->assertDatabaseHas("messages", [
            "company_id" => $company->id,
            "external_message_id" => "MSG3",
            "direction" => "inbound",
        ]);
    }

    public function test_history_synced_ingests_batch_with_correct_provider_and_directions(): void
    {
        $company = Company::create([
            "name" => "Empresa Baileys Historico",
            "slug" => "empresa-baileys-historico",
        ]);

        CompanyWhatsAppIntegration::create([
            "company_id" => $company->id,
            "provider" => "baileys",
            "integration_type" => "qr",
            "status" => "configured",
            "session_status" => "connected",
        ]);

        $this->postJson("/api/v1/webhooks/whatsapp/baileys", [
            "company_id" => $company->id,
            "event" => "history_synced",
            "data" => [
                "messages" => [
                    [
                        "phone" => "+5511966665555",
                        "body" => "Mensagem antiga do cliente",
                        "direction" => "inbound",
                        "external_message_id" => "HIST1",
                        "sent_at" => now()->subDays(20)->toISOString(),
                    ],
                    [
                        "phone" => "+5511966665555",
                        "body" => "Resposta antiga do atendimento",
                        "direction" => "outbound",
                        "external_message_id" => "HIST2",
                        "sent_at" => now()->subDays(19)->toISOString(),
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas("messages", [
            "company_id" => $company->id,
            "external_message_id" => "HIST1",
            "direction" => "inbound",
            "provider" => "baileys_qr_history",
        ]);

        $this->assertDatabaseHas("messages", [
            "company_id" => $company->id,
            "external_message_id" => "HIST2",
            "direction" => "outbound",
            "provider" => "baileys_qr_history",
        ]);

        $this->assertSame(2, Message::where("company_id", $company->id)->count());
    }

    public function test_history_synced_skips_incomplete_items_without_failing_the_batch(): void
    {
        $company = Company::create([
            "name" => "Empresa Baileys Historico Incompleto",
            "slug" => "empresa-baileys-historico-incompleto",
        ]);

        CompanyWhatsAppIntegration::create([
            "company_id" => $company->id,
            "provider" => "baileys",
            "integration_type" => "qr",
            "status" => "configured",
            "session_status" => "connected",
        ]);

        $this->postJson("/api/v1/webhooks/whatsapp/baileys", [
            "company_id" => $company->id,
            "event" => "history_synced",
            "data" => [
                "messages" => [
                    [
                        "phone" => "",
                        "body" => "Sem telefone, deve ser ignorada",
                        "external_message_id" => "HIST_BAD",
                        "sent_at" => now()->subDays(10)->toISOString(),
                    ],
                    [
                        "phone" => "+5511955554444",
                        "body" => "Mensagem válida",
                        "external_message_id" => "HIST_GOOD",
                        "sent_at" => now()->subDays(9)->toISOString(),
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseMissing("messages", [
            "company_id" => $company->id,
            "external_message_id" => "HIST_BAD",
        ]);

        $this->assertDatabaseHas("messages", [
            "company_id" => $company->id,
            "external_message_id" => "HIST_GOOD",
        ]);

        $this->assertSame(1, Message::where("company_id", $company->id)->count());
    }

    public function test_history_synced_is_idempotent_on_replay(): void
    {
        $company = Company::create([
            "name" => "Empresa Baileys Historico Replay",
            "slug" => "empresa-baileys-historico-replay",
        ]);

        CompanyWhatsAppIntegration::create([
            "company_id" => $company->id,
            "provider" => "baileys",
            "integration_type" => "qr",
            "status" => "configured",
            "session_status" => "connected",
        ]);

        $payload = [
            "company_id" => $company->id,
            "event" => "history_synced",
            "data" => [
                "messages" => [
                    [
                        "phone" => "+5511944443333",
                        "body" => "Mensagem repetida",
                        "external_message_id" => "HIST_REPLAY",
                        "sent_at" => now()->subDays(5)->toISOString(),
                    ],
                ],
            ],
        ];

        $this->postJson("/api/v1/webhooks/whatsapp/baileys", $payload)->assertOk();
        $this->postJson("/api/v1/webhooks/whatsapp/baileys", $payload)->assertOk();

        $this->assertSame(1, Message::where("company_id", $company->id)
            ->where("external_message_id", "HIST_REPLAY")
            ->count());
    }

    public function test_lid_resolved_updates_lead_created_from_an_unidentified_lid(): void
    {
        $company = Company::create([
            "name" => "Empresa Baileys Lid",
            "slug" => "empresa-baileys-lid",
        ]);

        CompanyWhatsAppIntegration::create([
            "company_id" => $company->id,
            "provider" => "baileys",
            "integration_type" => "qr",
            "status" => "configured",
            "session_status" => "connected",
        ]);

        // Mensagem chega antes do WhatsApp revelar o número real por trás do LID.
        $this->postJson("/api/v1/webhooks/whatsapp/baileys", [
            "company_id" => $company->id,
            "event" => "message_received",
            "data" => [
                "phone" => "lid:123456789",
                "body" => "Oi",
                "direction" => "inbound",
                "external_message_id" => "LIDMSG1",
                "sent_at" => now()->toISOString(),
            ],
        ])->assertOk();

        $this->assertDatabaseHas("leads", [
            "company_id" => $company->id,
            "phone_e164" => "lid:123456789",
        ]);

        // O qr-service descobre o número real (via contacts sync ou phoneNumberShare).
        $this->postJson("/api/v1/webhooks/whatsapp/baileys", [
            "company_id" => $company->id,
            "event" => "lid_resolved",
            "data" => [
                "lid" => "123456789",
                "phone" => "5511988887777",
            ],
        ])->assertOk();

        $this->assertDatabaseMissing("leads", [
            "company_id" => $company->id,
            "phone_e164" => "lid:123456789",
        ]);

        $this->assertDatabaseHas("leads", [
            "company_id" => $company->id,
            "phone_e164" => "+5511988887777",
        ]);

        $this->assertDatabaseHas("messages", [
            "company_id" => $company->id,
            "external_message_id" => "LIDMSG1",
        ]);
    }

    public function test_lid_resolved_is_ignored_when_integration_is_missing(): void
    {
        $this->postJson("/api/v1/webhooks/whatsapp/baileys", [
            "company_id" => 999999,
            "event" => "lid_resolved",
            "data" => [
                "lid" => "123456789",
                "phone" => "5511988887777",
            ],
        ])->assertNotFound();
    }
}
