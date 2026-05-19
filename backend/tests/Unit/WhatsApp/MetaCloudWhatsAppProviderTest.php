<?php

namespace Tests\Unit\WhatsApp;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\WhatsApp\MetaCloudWhatsAppProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaCloudWhatsAppProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_returns_failure_when_company_id_is_missing_in_context(): void
    {
        $provider = app(MetaCloudWhatsAppProvider::class);

        $result = $provider->sendTextMessage('+5511999999999', 'Olá', []);

        $this->assertFalse($result->success);
        $this->assertSame('meta_cloud', $result->provider);
        $this->assertSame('missing_company_id', $result->errorCode);
    }

    public function test_provider_returns_failure_when_company_integration_does_not_exist(): void
    {
        $company = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a-meta']);
        $provider = app(MetaCloudWhatsAppProvider::class);

        $result = $provider->sendTextMessage('+5511999999999', 'Olá', [
            'company_id' => $company->id,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('integration_not_found', $result->errorCode);
    }

    public function test_provider_returns_failure_when_integration_is_not_configured(): void
    {
        $company = Company::create(['name' => 'Empresa B', 'slug' => 'empresa-b-meta']);

        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
            'status' => CompanyWhatsAppIntegrationService::STATUS_NOT_CONFIGURED,
            'phone_number_id' => '123456',
            'business_account_id' => '789',
            'access_token_encrypted' => 'plain-token',
        ]);

        $provider = app(MetaCloudWhatsAppProvider::class);
        $result = $provider->sendTextMessage('+5511999999999', 'Olá', [
            'company_id' => $company->id,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('integration_not_configured', $result->errorCode);
    }

    public function test_provider_sends_expected_meta_request_when_company_is_configured(): void
    {
        config()->set('whatsapp.cloud_api_version', 'v22.0');

        $company = Company::create(['name' => 'Empresa C', 'slug' => 'empresa-c-meta']);
        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
            'status' => CompanyWhatsAppIntegrationService::STATUS_CONFIGURED,
            'phone_number_id' => '123456789',
            'business_account_id' => 'biz_001',
            'access_token_encrypted' => 'token-super-secreto',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.meta.123'],
                ],
            ], 200),
        ]);

        $provider = app(MetaCloudWhatsAppProvider::class);
        $result = $provider->sendTextMessage('+5511912345678', 'Mensagem teste', [
            'company_id' => $company->id,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('meta_cloud', $result->provider);
        $this->assertSame('wamid.meta.123', $result->externalMessageId);

        Http::assertSent(function ($request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://graph.facebook.com/v22.0/123456789/messages', (string) $request->url());
            $this->assertSame('Bearer token-super-secreto', $request->header('Authorization')[0] ?? null);

            $payload = $request->data();
            $this->assertSame('whatsapp', data_get($payload, 'messaging_product'));
            $this->assertSame('+5511912345678', data_get($payload, 'to'));
            $this->assertSame('text', data_get($payload, 'type'));
            $this->assertSame('Mensagem teste', data_get($payload, 'text.body'));

            return true;
        });

        $cipherInDatabase = (string) DB::table('company_whatsapp_integrations')
            ->where('company_id', $company->id)
            ->value('access_token_encrypted');

        $this->assertNotSame('token-super-secreto', $cipherInDatabase);
    }

    public function test_provider_returns_failure_with_api_error_when_meta_cloud_responds_http_error(): void
    {
        $company = Company::create(['name' => 'Empresa D', 'slug' => 'empresa-d-meta']);
        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
            'status' => CompanyWhatsAppIntegrationService::STATUS_CONFIGURED,
            'phone_number_id' => '999999',
            'business_account_id' => 'biz_002',
            'access_token_encrypted' => 'token-erro',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'code' => 131026,
                    'message' => 'Message undeliverable',
                ],
            ], 400),
        ]);

        $provider = app(MetaCloudWhatsAppProvider::class);
        $result = $provider->sendTextMessage('+5511990000000', 'Falha teste', [
            'company_id' => $company->id,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('meta_cloud', $result->provider);
        $this->assertSame('131026', $result->errorCode);
        $this->assertSame('Message undeliverable', $result->errorMessage);
        $this->assertNull($result->externalMessageId);
    }
}
