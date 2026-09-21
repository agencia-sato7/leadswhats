<?php

namespace Tests\Feature;

use App\Jobs\ProcessMetaCoexistenceHistory;
use App\Jobs\ProcessInboundWhatsAppMedia;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\User;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\WhatsappIngestionService;
use App\Services\WhatsApp\MetaWebhookIngestionService;
use App\Services\WhatsApp\MetaMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppDisconnectTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/settings/whatsapp/disconnect';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['whatsapp.meta_graph_api_version' => 'v25.0']);
    }

    private function connected(string $slug = 'disconnect'): Company
    {
        $company = Company::create(['name' => $slug, 'slug' => $slug]);
        app(CompanyWhatsAppIntegrationService::class)->completeCoexistenceForCompany(
            $company->id, ['waba_id' => 'waba-'.$slug, 'phone_number_id' => 'phone-'.$slug], 'test-secret',
        );
        return $company;
    }

    private function authenticate(Company $company, string $role = 'admin'): void
    {
        $user = User::factory()->create(['company_id' => $company->id, 'role' => $role, 'active' => true]);
        $token = 'test-'.$user->id;
        ApiToken::create(['user_id' => $user->id, 'name' => 'test', 'token_hash' => hash('sha256', $token)]);
        $this->withToken($token);
    }

    public function test_disconnect_preserves_crm_and_is_idempotent(): void
    {
        $company = $this->connected();
        $other = $this->connected('other');
        app(WhatsappIngestionService::class)->ingest($company, [
            'provider' => 'meta_cloud', 'phone' => '5511998887777', 'direction' => 'inbound',
            'channel' => 'text', 'body' => 'Preserve me', 'external_message_id' => 'preserved',
            'source' => 'meta_cloud', 'sent_at' => now()->toISOString(),
        ]);
        $this->authenticate($company, 'gestor');
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->postJson(self::URL, ['company_id' => $other->id])->assertOk()
            ->assertJsonPath('data.status', 'not_configured')
            ->assertJsonPath('data.access_token_configured', false)
            ->assertJsonPath('data.phone_number_id', null)
            ->assertJsonPath('data.is_coexistence', false);
        $this->assertDatabaseMissing('company_whatsapp_integrations', ['company_id' => $company->id]);
        $this->assertDatabaseHas('company_whatsapp_integrations', ['company_id' => $other->id]);
        $this->assertDatabaseHas('messages', ['external_message_id' => 'preserved']);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('conversations', 1);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://graph.facebook.com/v25.0/waba-disconnect/subscribed_apps'
            && $request->hasHeader('Authorization', 'Bearer test-secret'));
        $this->postJson(self::URL)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_meta_failure_and_false_success_preserve_credentials(): void
    {
        $company = $this->connected();
        $this->authenticate($company);
        foreach ([Http::response(['error' => ['code' => 190]], 400), Http::response(['success' => false])] as $response) {
            Http::fake(['*' => $response]);
            $this->postJson(self::URL)->assertStatus(502);
            $integration = CompanyWhatsAppIntegration::where('company_id', $company->id)->firstOrFail();
            $this->assertSame('test-secret', $integration->access_token_encrypted);
            $this->assertSame('error', $integration->status);
            $this->assertNotEmpty($integration->last_error);
        }
    }

    public function test_dead_token_subcode_467_disconnects_locally(): void
    {
        $company = $this->connected();
        $other = $this->connected('other');
        $this->authenticate($company, 'gestor');
        Http::fake(['*' => Http::response([
            'error' => ['code' => 190, 'type' => 'OAuthException', 'error_subcode' => 467],
        ], 401)]);

        $this->postJson(self::URL)->assertOk()
            ->assertJsonPath('data.status', 'not_configured')
            ->assertJsonPath('data.access_token_configured', false);

        $this->assertDatabaseMissing('company_whatsapp_integrations', ['company_id' => $company->id]);
        $this->assertDatabaseHas('company_whatsapp_integrations', ['company_id' => $other->id]);

        // Idempotente: segunda chamada não precisa (nem tenta) falar com a Meta.
        $this->postJson(self::URL)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_recoverable_meta_failure_marks_error_and_preserves_credentials(): void
    {
        $company = $this->connected();
        $this->authenticate($company);
        Http::fake(['*' => Http::response([
            'error' => ['code' => 190, 'type' => 'OAuthException', 'error_subcode' => 459],
        ], 401)]);

        $this->postJson(self::URL)->assertStatus(502);

        $integration = CompanyWhatsAppIntegration::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('test-secret', $integration->access_token_encrypted);
        $this->assertSame('error', $integration->status);
        $this->assertNotEmpty($integration->last_error);
    }

    public function test_shared_waba_is_not_unsubscribed(): void
    {
        $company = $this->connected();
        $other = $this->connected('other');
        CompanyWhatsAppIntegration::where('company_id', $other->id)->update(['waba_id' => 'waba-disconnect']);
        $this->authenticate($company);
        Http::fake();
        $this->postJson(self::URL)->assertStatus(409);
        $this->assertDatabaseCount('company_whatsapp_integrations', 2);
        Http::assertNothingSent();
    }

    public function test_old_jobs_and_webhooks_are_ignored_after_disconnect_and_reconnect(): void
    {
        $company = $this->connected();
        $oldId = CompanyWhatsAppIntegration::where('company_id', $company->id)->value('id');
        $this->authenticate($company);
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->postJson(self::URL)->assertOk();
        $counts = app(MetaWebhookIngestionService::class)->process([['changes' => [[
            'field' => 'history', 'value' => ['metadata' => ['phone_number_id' => 'phone-disconnect']],
        ]]]]);
        $this->assertSame(1, $counts['unmatched']);
        app(CompanyWhatsAppIntegrationService::class)->completeCoexistenceForCompany(
            $company->id, ['waba_id' => 'new-waba', 'phone_number_id' => 'new-phone'], 'new-token',
        );
        $history = $this->mock(MetaWebhookIngestionService::class);
        $history->shouldNotReceive('ingestHistoryValue');
        (new ProcessMetaCoexistenceHistory($company->id, $oldId, []))->handle($history);
        $ingestion = $this->mock(WhatsappIngestionService::class);
        $ingestion->shouldNotReceive('ingest');
        $media = $this->mock(MetaMediaService::class);
        $media->shouldNotReceive('download');
        (new ProcessInboundWhatsAppMedia($company->id, $oldId, [], []))->handle($ingestion, $media);
        $this->assertSame('configured', app(CompanyWhatsAppIntegrationService::class)->getForCompany($company->id)['status']);
    }

    public function test_permissions_and_read_only_context(): void
    {
        $company = $this->connected();
        Http::fake();
        $this->postJson(self::URL)->assertUnauthorized();
        foreach (['sdr', 'platform_admin'] as $role) {
            $this->authenticate($company, $role);
            $this->postJson(self::URL)->assertForbidden();
        }
        $this->authenticate($company);
        $this->withHeader('X-Tenant-Context', 'read-only')->postJson(self::URL)->assertForbidden();
        $this->assertDatabaseCount('company_whatsapp_integrations', 1);
        Http::assertNothingSent();
    }
}
