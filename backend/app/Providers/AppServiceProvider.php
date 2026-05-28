<?php

namespace App\Providers;

use App\Services\WhatsApp\DynamicWhatsAppProvider;
use App\Services\WhatsApp\FakeWhatsAppProvider;
use App\Services\WhatsApp\MetaCloudWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppProviderInterface;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $provider = (string) config('whatsapp.provider', 'dynamic');
        $allowFakeInProduction = (bool) config('whatsapp.allow_fake_in_production', false);
        $isProduction = (string) config('app.env') === 'production';

        if ($isProduction && $provider === 'fake' && !$allowFakeInProduction) {
            throw new RuntimeException('WHATSAPP_PROVIDER=fake não é permitido em production. Defina WHATSAPP_PROVIDER válido ou WHATSAPP_ALLOW_FAKE_IN_PRODUCTION=true conscientemente.');
        }

        $this->app->bind(WhatsAppProviderInterface::class, function () use ($provider) {
            return match ($provider) {
                'fake' => app(FakeWhatsAppProvider::class),
                'meta_cloud' => app(MetaCloudWhatsAppProvider::class),
                'dynamic' => app(DynamicWhatsAppProvider::class),
                default => throw new RuntimeException("WHATSAPP_PROVIDER inválido: {$provider}. Providers suportados: fake, meta_cloud, dynamic."),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

