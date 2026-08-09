<?php

namespace Tests\Unit\WhatsApp;

use App\Providers\AppServiceProvider;
use App\Services\WhatsApp\FakeWhatsAppProvider;
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
        config()->set('whatsapp.provider', 'unknown-provider');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WHATSAPP_PROVIDER inválido');

        (new AppServiceProvider(app()))->register();
    }

    public function test_fake_provider_is_allowed_outside_production(): void
    {
        config()->set('app.env', 'local');
        config()->set('whatsapp.provider', 'fake');

        (new AppServiceProvider(app()))->register();

        $this->assertInstanceOf(FakeWhatsAppProvider::class, app(WhatsAppProviderInterface::class));
    }

    public function test_fake_provider_is_rejected_in_production_without_override(): void
    {
        config()->set('app.env', 'production');
        config()->set('whatsapp.provider', 'fake');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('production');

        (new AppServiceProvider(app()))->register();
    }

    public function test_dynamic_provider_is_rejected(): void
    {
        config()->set('whatsapp.provider', 'dynamic');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WHATSAPP_PROVIDER inválido');

        (new AppServiceProvider(app()))->register();
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
