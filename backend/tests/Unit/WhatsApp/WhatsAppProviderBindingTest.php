<?php

namespace Tests\Unit\WhatsApp;

use App\Services\WhatsApp\WhatsAppProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WhatsAppProviderBindingTest extends TestCase
{
    use RefreshDatabase;

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
        putenv('WHATSAPP_PROVIDER');
        unset($_ENV['WHATSAPP_PROVIDER'], $_SERVER['WHATSAPP_PROVIDER']);

        parent::tearDown();
    }
}
