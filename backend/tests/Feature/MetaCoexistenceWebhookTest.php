<?php

namespace Tests\Feature;

use App\Jobs\ProcessMetaCoexistenceHistory;
use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\Message;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\WhatsappIngestionService;
use App\Services\WhatsApp\MetaWebhookIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaCoexistenceWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/webhooks/whatsapp/meta';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.cloud_app_secret', '');
        config()->set('whatsapp.cloud_webhook_verify_token', '');
    }

    public function test_smb_message_echoes_ingest_outbound_messages_from_the_business_app(): void
    {
        [$company, $integration] = $this->createConnectedCompany('echoes');

        $payload = $this->envelope($integration, 'smb_message_echoes', [
            'message_echoes' => [
                [
                    'id' => 'wamid.echo.1',
                    'from' => '15550001111',
                    'to' => '5511998887777',
                    'timestamp' => '1760000000',
                    'type' => 'text',
                    'text' => ['body' => 'Enviado pelo app WhatsApp Business'],
                ],
                [
                    'id' => 'wamid.echo.revoked',
                    'from' => '15550001111',
                    'to' => '5511998887777',
                    'timestamp' => '1760000060',
                    'type' => 'revoke',
                ],
            ],
        ]);

        $this->postJson(self::ENDPOINT, $payload)
            ->assertOk()
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.ignored', 1);

        $message = Message::query()->where('external_message_id', 'wamid.echo.1')->firstOrFail();
        $this->assertSame($company->id, $message->company_id);
        $this->assertSame('outbound', $message->direction);
        $this->assertSame('meta_cloud', $message->provider);
        $this->assertSame('smb_echo', $message->metadata['sync_source']);
        $this->assertDatabaseHas('leads', [
            'company_id' => $company->id,
            'phone_e164' => '+5511998887777',
        ]);
        $this->assertDatabaseMissing('messages', ['external_message_id' => 'wamid.echo.revoked']);
    }

    public function test_echo_of_a_message_sent_by_our_api_is_deduplicated(): void
    {
        [$company, $integration] = $this->createConnectedCompany('echo-dedupe');

        app(WhatsappIngestionService::class)->ingest($company, [
            'provider' => 'meta_cloud',
            'phone' => '5511998887777',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Enviado pela API do LEADSWHATS',
            'source' => 'meta_cloud',
            'external_message_id' => 'wamid.ours.1',
            'sent_at' => now()->toISOString(),
            'sync_source' => 'api',
        ]);

        $payload = $this->envelope($integration, 'smb_message_echoes', [
            'message_echoes' => [
                [
                    'id' => 'wamid.ours.1',
                    'from' => '15550001111',
                    'to' => '5511998887777',
                    'timestamp' => '1760000000',
                    'type' => 'text',
                    'text' => ['body' => 'Enviado pela API do LEADSWHATS'],
                ],
            ],
        ]);

        $this->postJson(self::ENDPOINT, $payload)
            ->assertOk()
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.duplicated', 1);

        $this->assertSame(1, Message::query()->where('external_message_id', 'wamid.ours.1')->count());
    }

    public function test_history_webhook_is_queued_and_imported_without_realtime_rules(): void
    {
        [$company, $integration] = $this->createConnectedCompany('history');
        Queue::fake();

        $payload = $this->envelope($integration, 'history', [
            'history' => [
                [
                    'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 55],
                    'threads' => [
                        [
                            'id' => '5511998887777',
                            'messages' => [
                                [
                                    'id' => 'wamid.hist.1',
                                    'from' => '5511998887777',
                                    'timestamp' => '1759000000',
                                    'type' => 'text',
                                    'text' => ['body' => 'Oi, tudo bem?'],
                                ],
                                [
                                    'id' => 'wamid.hist.2',
                                    'from' => '15550001111',
                                    'timestamp' => '1759000600',
                                    'type' => 'text',
                                    'text' => ['body' => 'Tudo ótimo! Como posso ajudar?'],
                                ],
                                [
                                    'id' => 'wamid.hist.3',
                                    'from' => '15550001111',
                                    'timestamp' => '1759172400',
                                    'type' => 'text',
                                    'text' => ['body' => 'Conseguiu ver minha proposta?'],
                                ],
                                [
                                    'id' => 'wamid.hist.media',
                                    'from' => '5511998887777',
                                    'timestamp' => '1759172500',
                                    'type' => 'media_placeholder',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->postJson(self::ENDPOINT, $payload)
            ->assertOk()
            ->assertJsonPath('data.queued', 1)
            ->assertJsonPath('data.processed', 0);

        Queue::assertPushed(ProcessMetaCoexistenceHistory::class, 1);

        $job = new ProcessMetaCoexistenceHistory(
            $company->id,
            $integration->id,
            $payload['entry'][0]['changes'][0]['value'],
        );
        $job->handle(app(MetaWebhookIngestionService::class));

        $this->assertSame(3, Message::query()->where('company_id', $company->id)->count());
        $this->assertDatabaseHas('messages', ['external_message_id' => 'wamid.hist.1', 'direction' => 'inbound']);
        $this->assertDatabaseHas('messages', ['external_message_id' => 'wamid.hist.2', 'direction' => 'outbound']);
        $this->assertDatabaseMissing('messages', ['external_message_id' => 'wamid.hist.media']);

        // 47h após o último envio: sem a flag de histórico seria marcado como resgate.
        $this->assertFalse((bool) Message::query()->where('external_message_id', 'wamid.hist.3')->value('is_rescue'));

        $historical = Message::query()->where('external_message_id', 'wamid.hist.2')->firstOrFail();
        $this->assertTrue((bool) $historical->metadata['is_historical_sync']);
        $this->assertSame('history', $historical->metadata['sync_source']);

        $this->assertSame(
            CompanyWhatsAppIntegrationService::SYNC_STATUS_REQUESTED,
            CompanyWhatsAppIntegration::query()->where('company_id', $company->id)->value('history_sync_status'),
        );

        // Reprocessar a mesma entrega não duplica nada (idempotência por wamid).
        $job->handle(app(MetaWebhookIngestionService::class));
        $this->assertSame(3, Message::query()->where('company_id', $company->id)->count());
    }

    public function test_history_progress_100_marks_sync_as_completed(): void
    {
        [$company, $integration] = $this->createConnectedCompany('history-progress');

        $value = [
            'metadata' => ['phone_number_id' => $integration->phone_number_id],
            'history' => [
                [
                    'metadata' => ['phase' => 2, 'chunk_order' => 9, 'progress' => 100],
                    'threads' => [
                        [
                            'id' => '5511998881111',
                            'messages' => [
                                [
                                    'id' => 'wamid.hist.done.1',
                                    'from' => '5511998881111',
                                    'timestamp' => '1759000000',
                                    'type' => 'text',
                                    'text' => ['body' => 'Última mensagem do histórico'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        app(MetaWebhookIngestionService::class)->ingestHistoryValue($company, $integration, $value);

        $this->assertSame(
            CompanyWhatsAppIntegrationService::SYNC_STATUS_COMPLETED,
            CompanyWhatsAppIntegration::query()->where('company_id', $company->id)->value('history_sync_status'),
        );
    }

    public function test_account_update_refreshes_connection_and_flags_unlink_events(): void
    {
        [$company, $integration] = $this->createConnectedCompany('account-update');

        $integration->forceFill(['connected_at' => null])->save();

        $this->postJson(self::ENDPOINT, $this->accountUpdateEnvelope('waba-account-update', 'PARTNER_ADDED'))->assertOk();
        $this->assertNotNull($integration->fresh()->connected_at);

        $this->postJson(self::ENDPOINT, $this->accountUpdateEnvelope('waba-account-update', 'PARTNER_REMOVED'))->assertOk();

        $integration->refresh();
        $this->assertSame(CompanyWhatsAppIntegrationService::STATUS_ERROR, $integration->status);
        $this->assertNotNull($integration->last_error);
        $this->assertSame($company->id, $integration->company_id);
    }

    public function test_messages_field_keeps_the_original_inbound_flow(): void
    {
        [$company, $integration] = $this->createConnectedCompany('messages');

        $payload = $this->envelope($integration, 'messages', [
            'contacts' => [[
                'wa_id' => '5511998887777',
                'profile' => ['name' => 'Nome do WhatsApp'],
            ]],
            'messages' => [
                [
                    'id' => 'wamid.messages.1',
                    'from' => '5511998887777',
                    'timestamp' => '1760000000',
                    'type' => 'text',
                    'text' => ['body' => 'Olá via coexistência'],
                ],
            ],
        ]);

        $this->postJson(self::ENDPOINT, $payload)
            ->assertOk()
            ->assertJsonPath('data.processed', 1);

        $this->assertDatabaseHas('messages', [
            'company_id' => $company->id,
            'external_message_id' => 'wamid.messages.1',
            'direction' => 'inbound',
            'body' => 'Olá via coexistência',
        ]);
        $this->assertDatabaseHas('leads', [
            'company_id' => $company->id,
            'phone_e164' => '+5511998887777',
            'name' => 'Nome do WhatsApp',
        ]);
    }

    public function test_unknown_field_without_messages_is_ignored(): void
    {
        [, $integration] = $this->createConnectedCompany('unknown-field');

        $this->postJson(self::ENDPOINT, $this->envelope($integration, 'unknown_field', [
            'something_else' => ['id' => '123'],
        ]))
            ->assertOk()
            ->assertJsonPath('data.processed', 0)
            ->assertJsonPath('data.ignored', 1);
    }

    /**
     * @return array{0: Company, 1: CompanyWhatsAppIntegration}
     */
    private function createConnectedCompany(string $slug): array
    {
        $company = Company::create([
            'name' => 'Empresa coexistência '.$slug,
            'slug' => 'coexistence-'.$slug,
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'internal-token-'.$slug,
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

        $integration = CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
            'status' => CompanyWhatsAppIntegrationService::STATUS_CONFIGURED,
            'connection_mode' => CompanyWhatsAppIntegrationService::CONNECTION_MODE_COEXISTENCE,
            'phone_number_id' => 'phone-number-id-'.$slug,
            'waba_id' => 'waba-'.$slug,
            'business_account_id' => 'waba-'.$slug,
            'access_token_encrypted' => 'token-local-'.$slug,
            'webhook_verify_token' => 'meta-verify-'.$slug,
        ]);

        return [$company, $integration];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function envelope(CompanyWhatsAppIntegration $integration, string $field, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => $integration->waba_id,
                    'changes' => [
                        [
                            'field' => $field,
                            'value' => array_merge([
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550001111',
                                    'phone_number_id' => $integration->phone_number_id,
                                ],
                            ], $value),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountUpdateEnvelope(string $wabaId, string $event): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => $wabaId,
                    'changes' => [
                        [
                            'field' => 'account_update',
                            'value' => [
                                'event' => $event,
                                'waba_info' => ['waba_id' => $wabaId],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}