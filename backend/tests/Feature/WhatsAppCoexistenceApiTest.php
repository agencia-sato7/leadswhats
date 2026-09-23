<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\User;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\WhatsApp\MetaCoexistenceTokenExchangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WhatsAppCoexistenceApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/settings/whatsapp/coexistence/complete';

    private const SYNC_ENDPOINT = '/api/v1/settings/whatsapp/coexistence/sync';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.meta_app_id', 'meta-app-test');
        config()->set('whatsapp.meta_app_secret', 'meta-secret-test');
        config()->set('whatsapp.legacy_meta_redirect_uri', 'https://developers.facebook.com/temporary-callback?nonce=legacy');
        config()->set('whatsapp.meta_graph_api_version', 'v25.0');
        config()->set('whatsapp.coexistence_config_id', 'coexistence-config-test');
        config()->set('whatsapp.coexistence_auto_sync', true);

        Http::preventStrayRequests();
    }

    public function test_token_exchange_service_ignores_legacy_redirect_uri(): void
    {
        Log::spy();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'access_token' => 'server-token-test',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ]),
        ]);

        $token = app(MetaCoexistenceTokenExchangeService::class)->exchange('one-time-code-test');

        $this->assertSame('server-token-test', $token['access_token']);
        $this->assertSame(5184000, $token['expires_in']);

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://graph.facebook.com/v25.0/oauth/access_token'
                && $request['client_id'] === 'meta-app-test'
                && $request['client_secret'] === 'meta-secret-test'
                && $request['code'] === 'one-time-code-test'
                && $request['grant_type'] === 'authorization_code'
                && ! array_key_exists('redirect_uri', $request->data())
                && str_starts_with((string) $request->header('Content-Type')[0], 'application/json');
        });

        Log::shouldHaveReceived('info')->once()->withArgs(
            function (string $message, array $context): bool {
                $serialized = json_encode([$message, $context]);

                return $message === 'Meta Coexistence OAuth request metadata.'
                    && $context['parameter_names'] === ['client_id', 'client_secret', 'code', 'grant_type']
                    && $context['client_id'] === 'meta-app-test'
                    && $context['redirect_uri'] === null
                    && $context['graph_api_version'] === 'v25.0'
                    && is_string($serialized)
                    && ! str_contains($serialized, 'meta-secret-test')
                    && ! str_contains($serialized, 'one-time-code-test');
            },
        );
    }

    public function test_completes_coexistence_connection_subscribes_app_and_requests_sync(): void
    {
        $company = $this->createCompany('coexistence-ok');
        $admin = $this->createUser($company->id, 'admin', 'coexistence.ok@test.local');

        $this->fakeCoexistenceApis();

        $response = $this->withToken($this->login($admin->email))
            ->postJson(self::ENDPOINT, $this->validPayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'configured')
            ->assertJsonPath('data.connection_mode', 'coexistence')
            ->assertJsonPath('data.is_coexistence', true)
            ->assertJsonPath('data.access_token_configured', true)
            ->assertJsonPath('data.waba_id', 'waba-test-1')
            ->assertJsonPath('data.phone_number_id', 'phone-test-1')
            ->assertJsonPath('data.contacts_sync_status', 'requested')
            ->assertJsonPath('data.history_sync_status', 'requested')
            ->assertJsonPath('data.coexistence_config_id', 'coexistence-config-test')
            ->assertJsonPath('data.coexistence_feature_type', 'whatsapp_business_app_onboarding')
            ->assertJsonMissingPath('data.access_token');

        $this->assertNotNull($response->json('data.coexistence_opted_in_at'));
        $this->assertNotNull($response->json('data.token_expires_at'));

        $integration = CompanyWhatsAppIntegration::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame(CompanyWhatsAppIntegrationService::CONNECTION_MODE_COEXISTENCE, $integration->connection_mode);
        $this->assertSame('server-token-test', $integration->access_token_encrypted);
        $this->assertNotNull($integration->webhook_verify_token);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/waba-test-1/subscribed_apps')
            && $request->hasHeader('Authorization', 'Bearer server-token-test'));
        Http::assertSent(fn (ClientRequest $request): bool => ($request->data()['sync_type'] ?? null) === 'contacts'
            && $request['messaging_product'] === 'whatsapp');
        Http::assertSent(fn (ClientRequest $request): bool => ($request->data()['sync_type'] ?? null) === 'history'
            && $request['messaging_product'] === 'whatsapp');
    }

    public function test_rejects_access_token_and_unknown_finish_event(): void
    {
        $company = $this->createCompany('coexistence-validation');
        $gestor = $this->createUser($company->id, 'gestor', 'coexistence.validation@test.local');
        $token = $this->login($gestor->email);

        $this->fakeCoexistenceApis();

        $payload = $this->validPayload();
        $payload['access_token'] = 'token-nao-permitido';
        $this->withToken($token)->postJson(self::ENDPOINT, $payload)->assertStatus(422);

        $payload = $this->validPayload();
        $payload['coexistence']['event'] = 'CANCEL';
        $this->withToken($token)->postJson(self::ENDPOINT, $payload)->assertStatus(422);

        $payload = $this->validPayload();
        unset($payload['coexistence']['data']['waba_id']);
        $this->withToken($token)->postJson(self::ENDPOINT, $payload)->assertStatus(422);

        $this->assertDatabaseCount('company_whatsapp_integrations', 0);
        Http::assertNothingSent();
    }

    public function test_meta_token_error_returns_safe_meta_error(): void
    {
        $company = $this->createCompany('coexistence-meta-error');
        $admin = $this->createUser($company->id, 'admin', 'coexistence.meta.error@test.local');

        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token' => Http::response([
                'error' => [
                    'message' => 'Redirect URI mismatch.',
                    'type' => 'OAuthException',
                    'code' => 100,
                    'error_subcode' => 36008,
                ],
            ], 400),
        ]);

        $this->withToken($this->login($admin->email))
            ->postJson(self::ENDPOINT, $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('message', 'A Meta recusou a autorização por uma configuração de redirecionamento incompatível. Inicie uma nova conexão.')
            ->assertJsonPath('meta_error.code', 100)
            ->assertJsonPath('meta_error.type', 'OAuthException');

        $this->assertDatabaseCount('company_whatsapp_integrations', 0);
    }

    public function test_subscribe_failure_marks_integration_as_error_without_discarding_credentials(): void
    {
        $company = $this->createCompany('coexistence-subscribe-error');
        $admin = $this->createUser($company->id, 'admin', 'coexistence.subscribe.error@test.local');

        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token' => Http::response(['access_token' => 'server-token-test']),
            'https://graph.facebook.com/*/subscribed_apps' => Http::response([
                'error' => ['message' => 'Permission denied', 'type' => 'OAuthException', 'code' => 200],
            ], 403),
        ]);

        $this->withToken($this->login($admin->email))
            ->postJson(self::ENDPOINT, $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('message', 'Não foi possível assinar o aplicativo no WhatsApp Business Account.');

        $integration = CompanyWhatsAppIntegration::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame(CompanyWhatsAppIntegrationService::STATUS_ERROR, $integration->status);
        $this->assertNotNull($integration->last_error);
        $this->assertSame('server-token-test', $integration->access_token_encrypted);
    }

    public function test_access_token_is_encrypted_at_rest_and_never_returned(): void
    {
        $company = $this->createCompany('coexistence-encrypted');
        $admin = $this->createUser($company->id, 'admin', 'coexistence.encrypted@test.local');

        $this->fakeCoexistenceApis();

        $response = $this->withToken($this->login($admin->email))
            ->postJson(self::ENDPOINT, $this->validPayload())
            ->assertOk();

        $rawValue = (string) DB::table('company_whatsapp_integrations')
            ->where('company_id', $company->id)
            ->value('access_token_encrypted');

        $this->assertNotSame('server-token-test', $rawValue);
        $this->assertNotSame('', $rawValue);
        $this->assertStringNotContainsString('server-token-test', $response->getContent());
    }

    public function test_sync_endpoint_requests_selected_sync_types(): void
    {
        $company = $this->createCompany('coexistence-sync');
        $gestor = $this->createUser($company->id, 'gestor', 'coexistence.sync@test.local');
        $token = $this->login($gestor->email);

        $this->fakeCoexistenceApis();

        $this->withToken($token)->postJson(self::ENDPOINT, $this->validPayload())->assertOk();

        Http::fake([
            'https://graph.facebook.com/*/smb_app_data' => Http::response(['success' => true]),
        ]);

        $this->withToken($token)
            ->postJson(self::SYNC_ENDPOINT, ['sync_type' => 'history'])
            ->assertOk()
            ->assertJsonPath('data.history_sync_status', 'requested');

        Http::assertSent(fn (ClientRequest $request): bool => $request['sync_type'] === 'history');
        Http::assertNotSent(fn (ClientRequest $request): bool => $request['sync_type'] === 'contacts');

        $this->withToken($token)
            ->postJson(self::SYNC_ENDPOINT, ['sync_type' => 'both'])
            ->assertOk();

        $this->withToken($token)
            ->postJson(self::SYNC_ENDPOINT, ['sync_type' => 'invalid'])
            ->assertStatus(422);
    }

    public function test_sync_endpoint_requires_configured_integration(): void
    {
        $company = $this->createCompany('coexistence-sync-empty');
        $admin = $this->createUser($company->id, 'admin', 'coexistence.sync.empty@test.local');

        Http::fake();

        $this->withToken($this->login($admin->email))
            ->postJson(self::SYNC_ENDPOINT, ['sync_type' => 'history'])
            ->assertStatus(409);
    }

    public function test_unauthenticated_and_sdr_are_blocked_on_coexistence_endpoints(): void
    {
        $this->postJson(self::ENDPOINT, $this->validPayload())->assertUnauthorized();
        $this->postJson(self::SYNC_ENDPOINT, ['sync_type' => 'history'])->assertUnauthorized();

        $company = $this->createCompany('coexistence-sdr');
        $sdr = $this->createUser($company->id, 'sdr', 'coexistence.sdr@test.local');
        $token = $this->login($sdr->email);

        $this->withToken($token)->postJson(self::ENDPOINT, $this->validPayload())->assertForbidden();
        $this->withToken($token)->postJson(self::SYNC_ENDPOINT, ['sync_type' => 'history'])->assertForbidden();
    }

    private function fakeCoexistenceApis(): void
    {
        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token' => Http::response([
                'access_token' => 'server-token-test',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ]),
            'https://graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true]),
            'https://graph.facebook.com/*/smb_app_data' => Http::response(['success' => true]),
        ]);
    }

    private function createCompany(string $slug): Company
    {
        return Company::create([
            'name' => 'Empresa '.$slug,
            'slug' => $slug,
        ]);
    }

    private function createUser(int $companyId, string $role, string $email): User
    {
        return User::create([
            'company_id' => $companyId,
            'name' => strtoupper($role),
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => $role,
            'active' => true,
        ]);
    }

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
        ])->json('token');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'code' => 'one-time-code-test',
            'coexistence' => [
                'type' => 'WA_EMBEDDED_SIGNUP',
                'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
                'data' => [
                    'phone_number_id' => 'phone-test-1',
                    'waba_id' => 'waba-test-1',
                    'business_id' => 'business-test-1',
                    'page_ids' => ['page-test-1'],
                    'catalog_ids' => ['catalog-test-1'],
                    'dataset_ids' => ['dataset-test-1'],
                    'instagram_account_ids' => ['instagram-test-1'],
                ],
            ],
        ];
    }
}