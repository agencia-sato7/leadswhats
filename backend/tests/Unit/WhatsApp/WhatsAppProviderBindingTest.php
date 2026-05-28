<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\MetaCloudWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WhatsAppProviderBindingTest extends TestCase
{
    use RefreshDatabase;

    private ?string $originalProvider = null;

    protected function setUp(): void
    {
        parent::setUp();
        $val = getenv('WHATSAPP_PROVIDER');
        $this->originalProvider = $val !== false ? $val : null;
    }

    public function test_meta_cloud_provider_can_be_resolved_from_container(): void
    {
        putenv('WHATSAPP_PROVIDER=meta_cloud');
        $_ENV['WHATSAPP_PROVIDER'] = 'meta_cloud';
        $_SERVER['WHATSAPP_PROVIDER'] = 'meta_cloud';

        $this->refreshApplication();

        $provider = app()->make(WhatsAppProviderInterface::class);

        $this->assertInstanceOf(MetaCloudWhatsAppProvider::class, $provider);
    }

    public function test_unknown_provider_fails_with_clear_message(): void
    {
        putenv('WHATSAPP_PROVIDER=unknown-provider');
        $_ENV['WHATSAPP_PROVIDER'] = 'unknown-provider';
        $_SERVER['WHATSAPP_PROVIDER'] = 'unknown-provider';
        $this->refreshApplication();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WHATSAPP_PROVIDER inválido');

        app()->make(WhatsAppProviderInterface::class);
    }

    protected function tearDown(): void
    {
        if ($this->originalProvider !== null) {
            putenv('WHATSAPP_PROVIDER=' . $this->originalProvider);
            $_ENV['WHATSAPP_PROVIDER'] = $this->originalProvider;
            $_SERVER['WHATSAPP_PROVIDER'] = $this->originalProvider;
        } else {
            putenv('WHATSAPP_PROVIDER');
            unset($_ENV['WHATSAPP_PROVIDER'], $_SERVER['WHATSAPP_PROVIDER']);
        }

        parent::tearDown();
    }
}
