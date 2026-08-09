<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\FakeWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FakeWhatsAppProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_resolves_whatsapp_provider_interface_to_fake_provider(): void
    {
        $provider = app(WhatsAppProviderInterface::class);

        $this->assertInstanceOf(FakeWhatsAppProvider::class, $provider);
    }

    public function test_fake_provider_returns_success_result_with_provider_and_external_id(): void
    {
        $provider = app(WhatsAppProviderInterface::class);

        $result = $provider->sendTextMessage("5511999999999", "Mensagem de teste", [
            "conversation_id" => 123,
            "lead_id" => 456,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame("fake", $result->provider);
        $this->assertNotNull($result->externalMessageId);
        $this->assertStringStartsWith("fake-", $result->externalMessageId);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->errorMessage);
        $this->assertIsArray($result->rawResponse);
        $this->assertTrue($result->rawResponse["simulated"] ?? false);
    }

    public function test_fake_provider_is_deterministic_and_performs_no_external_call(): void
    {
        Http::preventStrayRequests();
        $provider = app(WhatsAppProviderInterface::class);

        $first = $provider->sendTextMessage("5511888888888", "Mensagem A", ["company_id" => 10]);
        $second = $provider->sendTextMessage("5511888888888", "Mensagem A", ["company_id" => 10]);

        $this->assertSame($first->externalMessageId, $second->externalMessageId);
        $this->assertSame("fake", $first->provider);
        $this->assertSame("fake", $second->provider);
    }

    public function test_fake_provider_refuses_direct_execution_in_production(): void
    {
        config()->set('app.env', 'production');
        $provider = new FakeWhatsAppProvider();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('production');

        $provider->sendTextMessage('5511999999999', 'Não deve enviar');
    }
}
