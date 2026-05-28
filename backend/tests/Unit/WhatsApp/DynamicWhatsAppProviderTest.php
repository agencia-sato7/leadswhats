<?php

namespace Tests\Unit\WhatsApp;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\WhatsApp\DynamicWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DynamicWhatsAppProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_provider_returns_failure_when_company_id_is_missing_in_context(): void
    {
        $provider = app(DynamicWhatsAppProvider::class);

        $result = $provider->sendTextMessage('+5511999999999', 'Olá', []);

        $this->assertFalse($result->success);
        $this->assertSame('dynamic', $result->provider);
        $this->assertSame('whatsapp_disconnected', $result->errorCode);
    }

    public function test_dynamic_provider_returns_failure_when_company_has_no_integration(): void
    {
        $company = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a-dyn']);
        $provider = app(DynamicWhatsAppProvider::class);

        $result = $provider->sendTextMessage('+5511999999999', 'Olá', [
            'company_id' => $company->id,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('dynamic', $result->provider);
        $this->assertSame('whatsapp_disconnected', $result->errorCode);
    }

    public function test_dynamic_provider_returns_failure_when_integration_is_not_configured(): void
    {
        $company = Company::create(['name' => 'Empresa B', 'slug' => 'empresa-b-dyn']);

        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
            'status' => CompanyWhatsAppIntegrationService::STATUS_NOT_CONFIGURED,
            'phone_number_id' => '123',
            'business_account_id' => '456',
            'access_token_encrypted' => 'token',
        ]);

        $provider = app(DynamicWhatsAppProvider::class);
        $result = $provider->sendTextMessage('+5511999999999', 'Olá', [
            'company_id' => $company->id,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('dynamic', $result->provider);
        $this->assertSame('whatsapp_disconnected', $result->errorCode);
    }

    public function test_dynamic_provider_delegates_to_meta_cloud_provider_when_configured(): void
    {
        $company = Company::create(['name' => 'Empresa C', 'slug' => 'empresa-c-dyn']);

        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
            'status' => CompanyWhatsAppIntegrationService::STATUS_CONFIGURED,
            'phone_number_id' => '123456789',
            'business_account_id' => 'biz_001',
            'access_token_encrypted' => 'token-secreto',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.dyn.123'],
                ],
            ], 200),
        ]);

        $provider = app(DynamicWhatsAppProvider::class);
        $result = $provider->sendTextMessage('+5511912345678', 'Teste dynamic', [
            'company_id' => $company->id,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('meta_cloud', $result->provider);
        $this->assertSame('wamid.dyn.123', $result->externalMessageId);
    }
}
