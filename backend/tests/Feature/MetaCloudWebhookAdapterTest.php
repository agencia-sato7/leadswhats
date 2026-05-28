<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\Message;
use App\Services\CompanyWhatsAppIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaCloudWebhookAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_get_verification_valid_returns_challenge(): void
    {
        config()->set('whatsapp.cloud_webhook_verify_token', 'meta-verify-global');

        $this->get('/api/v1/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=meta-verify-global&hub.challenge=abc123')
            ->assertOk()
            ->assertSeeText('abc123');
    }

    public function test_meta_get_verification_invalid_returns_403(): void
    {
        config()->set('whatsapp.cloud_webhook_verify_token', 'meta-verify-global');

        $this->get('/api/v1/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=wrong-token&hub.challenge=abc123')
            ->assertStatus(403);
    }

    public function test_meta_post_inbound_text_creates_data_and_replay_is_idempotent(): void
    {
        $company = Company::create([
            'name' => 'Empresa Meta Inbound',
            'slug' => 'empresa-meta-inbound',
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'internal-token-meta',
            'timezone' => 'America/Sao_Paulo',
            'workday_start_time' => '08:00:00',
            'workday_end_time' => '18:00:00',
            'lunch_start_time' => '12:00:00',
            'lunch_end_time' => '13:00:00',
            'working_days' => [1, 2, 3, 4, 5],
            'first_response_sla_minutes' => 15,
            'follow_up_sla_hours' => 24,
            'stale_conversation_hours' => 48,
            'repeated_lead_window_days' => 90,
            'rescue_threshold_hours' => 24,
        ]);

        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
            'status' => CompanyWhatsAppIntegrationService::STATUS_CONFIGURED,
            'phone_number_id' => 'phone-number-id-1',
            'business_account_id' => 'biz-1',
            'access_token_encrypted' => 'token-local',
            'webhook_verify_token' => 'meta-verify-company',
        ]);

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => 'phone-number-id-1',
                                ],
                                'messages' => [
                                    [
                                        'id' => 'wamid.meta.adapter.1',
                                        'from' => '5511998887777',
                                        'timestamp' => '1717000000',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'Olá via Meta',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/v1/webhooks/whatsapp/meta', $payload)
            ->assertOk()
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.duplicated', 0);

        $this->postJson('/api/v1/webhooks/whatsapp/meta', $payload)
            ->assertOk()
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.duplicated', 1);

        $this->assertDatabaseHas('messages', [
            'company_id' => $company->id,
            'provider' => 'meta_cloud',
            'external_message_id' => 'wamid.meta.adapter.1',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Olá via Meta',
        ]);
    }

    public function test_meta_post_without_text_is_ignored_with_200(): void
    {
        $company = Company::create([
            'name' => 'Empresa Meta Ignored',
            'slug' => 'empresa-meta-ignored',
        ]);

        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
            'status' => CompanyWhatsAppIntegrationService::STATUS_CONFIGURED,
            'phone_number_id' => 'phone-number-id-ignored',
            'business_account_id' => 'biz-ignored',
            'access_token_encrypted' => 'token-local',
        ]);

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => 'phone-number-id-ignored',
                                ],
                                'messages' => [
                                    [
                                        'id' => 'wamid.meta.adapter.ignored.1',
                                        'from' => '5511988877665',
                                        'timestamp' => '1717000000',
                                        'type' => 'image',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/v1/webhooks/whatsapp/meta', $payload)
            ->assertOk()
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.ignored', 1)
            ->assertJsonPath('data.unmatched', 0);

        $this->assertDatabaseMissing('messages', [
            'external_message_id' => 'wamid.meta.adapter.ignored.1',
        ]);
    }

    public function test_meta_post_with_unknown_phone_number_id_returns_ignored_unmatched_with_200(): void
    {
        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'metadata' => [
                                    'phone_number_id' => 'phone-number-id-unknown',
                                ],
                                'messages' => [
                                    [
                                        'id' => 'wamid.meta.adapter.unknown.1',
                                        'from' => '5511977766655',
                                        'timestamp' => '1717000000',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'Sem empresa mapeada',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/v1/webhooks/whatsapp/meta', $payload)
            ->assertOk()
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.unmatched', 1);

        $this->assertDatabaseMissing('messages', [
            'external_message_id' => 'wamid.meta.adapter.unknown.1',
        ]);
    }

    public function test_internal_webhook_endpoint_still_requires_internal_token_and_processes_payload(): void
    {
        $company = Company::create([
            'name' => 'Empresa Webhook Interno',
            'slug' => 'empresa-webhook-interno',
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'internal-token',
            'timezone' => 'America/Sao_Paulo',
            'workday_start_time' => '08:00:00',
            'workday_end_time' => '18:00:00',
            'lunch_start_time' => '12:00:00',
            'lunch_end_time' => '13:00:00',
            'working_days' => [1, 2, 3, 4, 5],
            'first_response_sla_minutes' => 15,
            'follow_up_sla_hours' => 24,
            'stale_conversation_hours' => 48,
            'repeated_lead_window_days' => 90,
            'rescue_threshold_hours' => 24,
        ]);

        $payload = [
            'company_slug' => $company->slug,
            'provider' => 'whatsapp-cloud',
            'external_message_id' => 'wamid.internal.adapter.1',
            'phone' => '+5511991234567',
            'direction' => 'inbound',
            'body' => 'Mensagem interna normalizada',
            'sent_at' => now()->toISOString(),
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload)
            ->assertStatus(401);

        $this->withHeaders(['X-Webhook-Token' => 'internal-token'])
            ->postJson('/api/v1/webhooks/whatsapp', $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicated', false);

        $this->assertDatabaseHas('messages', [
            'company_id' => $company->id,
            'external_message_id' => 'wamid.internal.adapter.1',
            'direction' => 'inbound',
            'body' => 'Mensagem interna normalizada',
        ]);
    }
}
