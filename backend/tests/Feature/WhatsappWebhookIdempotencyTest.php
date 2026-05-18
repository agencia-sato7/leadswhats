<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_is_idempotent_when_external_message_id_is_replayed(): void
    {
        $company = Company::create([
            'name' => 'Empresa Teste',
            'slug' => 'empresa-teste',
            'timezone' => 'America/Sao_Paulo',
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'demo_idempotent_token',
        ]);

        $payload = [
            'company_slug' => $company->slug,
            'phone' => '(11) 98888-1111',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'channel' => 'text',
            'body' => 'Olá, mensagem de teste',
            'source' => 'instagram',
            'external_message_id' => 'wamid.1234567890',
            'sent_at' => '2026-05-06T10:00:00-03:00',
        ];

        $headers = ['X-Webhook-Token' => 'demo_idempotent_token'];

        $firstResponse = $this->postJson('/api/v1/webhooks/whatsapp', $payload, $headers);
        $firstResponse->assertOk()->assertJsonPath('data.duplicated', false);

        $secondResponse = $this->postJson('/api/v1/webhooks/whatsapp', $payload, $headers);
        $secondResponse->assertOk()->assertJsonPath('data.duplicated', true);

        $this->assertEquals(1, Message::count(), 'A mensagem não deve ser duplicada.');
        $this->assertEquals(1, Lead::count(), 'O lead não deve ser duplicado.');
        $this->assertEquals(1, Conversation::count(), 'A conversa não deve ser duplicada.');
    }
}
