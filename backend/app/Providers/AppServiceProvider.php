<?php

namespace App\Providers;

use App\Contracts\Intelligence\ConversationAnalyzer;
use App\Services\Intelligence\FakeConversationAnalyzer;
use App\Services\Intelligence\PythonConversationAnalyzer;
use App\Services\Intelligence\UnavailableConversationAnalyzer;
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
        $provider = (string) config('whatsapp.provider', 'meta_cloud');
        $isProduction = (string) config('app.env') === 'production';

        if (!in_array($provider, ['fake', 'meta_cloud'], true)) {
            throw new RuntimeException("WHATSAPP_PROVIDER inválido: {$provider}. Providers suportados: fake, meta_cloud.");
        }

        if ($isProduction && $provider !== 'meta_cloud') {
            throw new RuntimeException('Em production, WHATSAPP_PROVIDER deve ser meta_cloud.');
        }

        $this->app->bind(WhatsAppProviderInterface::class, function () use ($provider) {
            return match ($provider) {
                'fake' => app(FakeWhatsAppProvider::class),
                'meta_cloud' => app(MetaCloudWhatsAppProvider::class),
            };
        });

        $this->app->bind(ConversationAnalyzer::class, function () {
            $analyzer = (string) config('intelligence.analyzer', 'unavailable');

            if (!in_array($analyzer, ['python', 'fake', 'unavailable'], true)) {
                throw new RuntimeException("CONVERSATION_ANALYZER inválido: {$analyzer}. Valores suportados: python, fake, unavailable.");
            }

            if ($analyzer === 'fake' && !app()->environment(['local', 'testing'])) {
                throw new RuntimeException('CONVERSATION_ANALYZER=fake é permitido somente em local/testing.');
            }

            return match ($analyzer) {
                'python' => app(PythonConversationAnalyzer::class),
                'fake' => app(FakeConversationAnalyzer::class),
                'unavailable' => app(UnavailableConversationAnalyzer::class),
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
