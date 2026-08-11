<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\User;
use App\Services\WhatsApp\MetaEmbeddedSignupTokenExchangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WhatsAppEmbeddedSignupApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/settings/whatsapp/embedded-signup/complete';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.meta_app_id', 'meta-app-test');
        config()->set('whatsapp.meta_app_secret', 'meta-secret-test');
        config()->set('whatsapp.meta_redirect_uri', 'https://app.test/settings/whatsapp');
        config()->set('whatsapp.meta_graph_api_version', 'v25.0');
        Http::preventStrayRequests();
    }

    public function test_token_exchange_service_uses_server_credentials_with_http_fake(): void
    {
        Log::spy();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'access_token' => 'server-token-test',
                'token_type' => 'bearer',
            ]),
        ]);

        $token = app(MetaEmbeddedSignupTokenExchangeService::class)->exchange('one-time-code-test');

        $this->assertSame('server-token-test', $token);
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://graph.facebook.com/v25.0/oauth/access_token'
                && $request['client_id'] === 'meta-app-test'
                && $request['client_secret'] === 'meta-secret-test'
                && $request['code'] === 'one-time-code-test'
                && $request['grant_type'] === 'authorization_code'
                && $request['redirect_uri'] === 'https://app.test/settings/whatsapp'
                && str_starts_with((string) $request->header('Content-Type')[0], 'application/json');
        });

        Log::shouldHaveReceived('info')->once()->withArgs(
            function (string $message, array $context): bool {
                $serialized = json_encode([$message, $context]);

                return $message === 'Meta Embedded Signup OAuth request metadata.'
                    && $context === [
                        'url' => 'https://graph.facebook.com/v25.0/oauth/access_token',
                        'method' => 'POST',
                        'content_type' => 'application/json',
                        'parameter_names' => [
                            'client_id',
                            'client_secret',
                            'code',
                            'grant_type',
                            'redirect_uri',
                        ],
                        'redirect_uri' => 'https://app.test/settings/whatsapp',
                        'client_id' => 'meta-app-test',
                        'grant_type' => 'authorization_code',
                        'graph_api_version' => 'v25.0',
                    ]
                    && is_string($serialized)
                    && ! str_contains($serialized, 'meta-secret-test')
                    && ! str_contains($serialized, 'one-time-code-test')
                    && ! str_contains($serialized, 'server-token-test');
            },
        );
    }

    public function test_redirect_uri_is_required_server_configuration(): void
    {
        config()->set('whatsapp.meta_redirect_uri', '');

        $company = $this->createCompany('embedded-no-redirect');
        $admin = $this->createUser($company->id, 'admin', 'embedded.no.redirect@test.local');

        $this->withToken($this->login($admin->email))
            ->postJson(self::ENDPOINT, $this->validPayload())
            ->assertStatus(503)
            ->assertJsonPath('message', 'As credenciais do Embedded Signup não estão configuradas no servidor.');

        Http::assertNothingSent();
    }

    public function test_admin_completes_embedded_signup_and_persists_selected_company_integration(): void
    {
        $company = $this->createCompany('embedded-success');
        $admin = $this->createUser($company->id, 'admin', 'embedded.success@test.local');
        $payload = $this->validPayload();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'access_token' => 'meta-access-token-success',
            ]),
        ]);

        $response = $this->withToken($this->login($admin->email))
            ->postJson(self::ENDPOINT, $payload)
            ->assertOk()
            ->assertJsonPath('data.provider', 'meta_cloud')
            ->assertJsonPath('data.status', 'configured')
            ->assertJsonPath('data.phone_number_id', 'phone-test-100')
            ->assertJsonPath('data.waba_id', 'waba-test-100')
            ->assertJsonPath('data.business_account_id', 'waba-test-100')
            ->assertJsonPath('data.business_id', 'business-test-100')
            ->assertJsonPath('data.page_ids.0', 'page-test-1')
            ->assertJsonPath('data.catalog_ids.0', 'catalog-test-1')
            ->assertJsonPath('data.dataset_ids.0', 'dataset-test-1')
            ->assertJsonPath('data.instagram_account_ids.0', 'instagram-test-1')
            ->assertJsonPath('data.access_token_configured', true)
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.access_token_encrypted')
            ->assertJsonMissingPath('data.webhook_verify_token');

        $integration = CompanyWhatsAppIntegration::query()
            ->where('company_id', $company->id)
            ->firstOrFail();

        $this->assertSame('meta_cloud', $integration->provider);
        $this->assertSame('configured', $integration->status);
        $this->assertSame('phone-test-100', $integration->phone_number_id);
        $this->assertSame('waba-test-100', $integration->waba_id);
        $this->assertSame('waba-test-100', $integration->business_account_id);
        $this->assertSame('business-test-100', $integration->business_id);
        $this->assertSame(['page-test-1'], $integration->page_ids);
        $this->assertSame(['catalog-test-1'], $integration->catalog_ids);
        $this->assertSame(['dataset-test-1'], $integration->dataset_ids);
        $this->assertSame(['instagram-test-1'], $integration->instagram_account_ids);
        $this->assertNotNull($integration->connected_at);

        $body = $response->getContent();
        $this->assertStringNotContainsString($payload['code'], $body);
        $this->assertStringNotContainsString('meta-access-token-success', $body);
        $this->assertStringNotContainsString('meta-secret-test', $body);
    }

    public function test_code_is_required_and_no_meta_request_is_made(): void
    {
        $payload = $this->validPayload();
        unset($payload['code']);

        $this->postAsManager($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        Http::assertNothingSent();
    }

    public function test_access_token_from_frontend_is_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['access_token'] = 'frontend-token-must-be-rejected';

        $this->postAsManager($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['access_token']);

        Http::assertNothingSent();
    }

    public function test_embedded_signup_type_must_be_official_event_type(): void
    {
        $payload = $this->validPayload();
        $payload['embedded_signup']['type'] = 'UNSUPPORTED_TYPE';

        $this->postAsManager($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['embedded_signup.type']);

        Http::assertNothingSent();
    }

    public function test_embedded_signup_event_must_be_finish(): void
    {
        $payload = $this->validPayload();
        $payload['embedded_signup']['event'] = 'CANCEL';

        $this->postAsManager($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['embedded_signup.event']);

        Http::assertNothingSent();
    }

    public function test_waba_id_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['embedded_signup']['data']['waba_id']);

        $this->postAsManager($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['embedded_signup.data.waba_id']);

        Http::assertNothingSent();
    }

    public function test_phone_number_id_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['embedded_signup']['data']['phone_number_id']);

        $this->postAsManager($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['embedded_signup.data.phone_number_id']);

        Http::assertNothingSent();
    }

    public function test_sdr_and_platform_admin_cannot_complete_embedded_signup(): void
    {
        $company = $this->createCompany('embedded-forbidden');
        $sdr = $this->createUser($company->id, 'sdr', 'embedded.sdr@test.local');
        $platformAdmin = User::create([
            'company_id' => null,
            'name' => 'Platform Admin',
            'email' => 'embedded.platform@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'platform_admin',
            'active' => true,
        ]);

        $this->withToken($this->login($sdr->email))
            ->postJson(self::ENDPOINT, $this->validPayload())
            ->assertForbidden();

        $this->withToken($this->login($platformAdmin->email))
            ->postJson(self::ENDPOINT, $this->validPayload())
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_meta_api_error_is_sanitized_and_does_not_persist_integration(): void
    {
        $company = $this->createCompany('embedded-meta-error');
        $admin = $this->createUser($company->id, 'admin', 'embedded.error@test.local');
        $payload = $this->validPayload();
        Log::spy();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Rejected meta-secret-test meta-access-token-leak '.$payload['code'],
                    'type' => 'OAuthException',
                    'code' => 100,
                    'error_subcode' => 36008,
                ],
            ], 400),
        ]);

        $response = $this->withToken($this->login($admin->email))
            ->postJson(self::ENDPOINT, $payload)
            ->assertStatus(502)
            ->assertJsonPath('message', 'A Meta não aceitou a conclusão do Embedded Signup.')
            ->assertJsonPath('meta_error.code', 100)
            ->assertJsonPath('meta_error.type', 'OAuthException')
            ->assertJsonPath('meta_error.error_subcode', 36008);

        $body = $response->getContent();
        $this->assertStringNotContainsString($payload['code'], $body);
        $this->assertStringNotContainsString('meta-secret-test', $body);
        $this->assertStringNotContainsString('meta-access-token-leak', $body);
        $this->assertDatabaseMissing('company_whatsapp_integrations', ['company_id' => $company->id]);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            function (string $message, array $context) use ($payload): bool {
                $serialized = json_encode([$message, $context]);

                return $message === 'Meta Embedded Signup OAuth exchange failed.'
                    && $context === [
                        'http_status' => 400,
                        'error_code' => 100,
                        'error_type' => 'OAuthException',
                        'error_subcode' => 36008,
                    ]
                    && is_string($serialized)
                    && ! str_contains($serialized, $payload['code'])
                    && ! str_contains($serialized, 'meta-secret-test')
                    && ! str_contains($serialized, 'meta-access-token-leak');
            },
        );
    }

    public function test_existing_integration_is_updated_idempotently_without_duplicate_rows(): void
    {
        $company = $this->createCompany('embedded-idempotent');
        $gestor = $this->createUser($company->id, 'gestor', 'embedded.idempotent@test.local');

        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => 'meta_cloud',
            'status' => 'configured',
            'phone_number_id' => 'old-phone-id',
            'business_account_id' => 'old-waba-id',
            'waba_id' => 'old-waba-id',
            'business_id' => 'old-business-id',
            'access_token_encrypted' => 'old-access-token',
            'webhook_verify_token' => 'existing-webhook-token',
            'connected_at' => now()->subDay(),
        ]);

        Http::fakeSequence()
            ->push(['access_token' => 'first-exchanged-token'])
            ->push(['access_token' => 'second-exchanged-token']);

        $token = $this->login($gestor->email);
        $this->withToken($token)->postJson(self::ENDPOINT, $this->validPayload())->assertOk();

        $secondPayload = $this->validPayload();
        $secondPayload['code'] = 'second-one-time-code';
        $secondPayload['embedded_signup']['data']['phone_number_id'] = 'phone-test-updated';
        $secondPayload['embedded_signup']['data']['business_id'] = 'business-test-updated';

        $this->withToken($token)->postJson(self::ENDPOINT, $secondPayload)->assertOk();

        $this->assertSame(1, CompanyWhatsAppIntegration::query()->where('company_id', $company->id)->count());

        $integration = CompanyWhatsAppIntegration::query()->where('company_id', $company->id)->firstOrFail();
        $this->assertSame('phone-test-updated', $integration->phone_number_id);
        $this->assertSame('business-test-updated', $integration->business_id);
        $this->assertSame('second-exchanged-token', $integration->access_token_encrypted);
        $this->assertSame('existing-webhook-token', $integration->webhook_verify_token);
    }

    public function test_access_token_is_encrypted_at_rest_and_never_returned(): void
    {
        $company = $this->createCompany('embedded-encrypted');
        $admin = $this->createUser($company->id, 'admin', 'embedded.encrypted@test.local');

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'access_token' => 'plain-meta-token-must-not-be-stored',
            ]),
        ]);

        $response = $this->withToken($this->login($admin->email))
            ->postJson(self::ENDPOINT, $this->validPayload())
            ->assertOk();

        $rawValue = (string) DB::table('company_whatsapp_integrations')
            ->where('company_id', $company->id)
            ->value('access_token_encrypted');

        $this->assertNotSame('plain-meta-token-must-not-be-stored', $rawValue);
        $this->assertNotSame('', $rawValue);
        $this->assertStringNotContainsString('plain-meta-token-must-not-be-stored', $response->getContent());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postAsManager(array $payload)
    {
        $company = $this->createCompany('validation-'.strtolower(fake()->unique()->lexify('????????')));
        $gestor = $this->createUser(
            $company->id,
            'gestor',
            fake()->unique()->safeEmail(),
        );

        return $this->withToken($this->login($gestor->email))->postJson(self::ENDPOINT, $payload);
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
            'embedded_signup' => [
                'data' => [
                    'phone_number_id' => 'phone-test-100',
                    'waba_id' => 'waba-test-100',
                    'business_id' => 'business-test-100',
                    'page_ids' => ['page-test-1'],
                    'catalog_ids' => ['catalog-test-1'],
                    'dataset_ids' => ['dataset-test-1'],
                    'instagram_account_ids' => ['instagram-test-1'],
                ],
                'type' => 'WA_EMBEDDED_SIGNUP',
                'event' => 'FINISH',
            ],
        ];
    }
}
