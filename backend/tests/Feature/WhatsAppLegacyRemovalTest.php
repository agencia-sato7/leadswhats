<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Services\Domain\LeadSourceService;
use App\Services\WhatsappIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class WhatsAppLegacyRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_provider_and_source_values_are_rejected_for_new_ingestion(): void
    {
        $company = Company::create(['name' => 'Policy Company', 'slug' => 'policy-company']);
        $service = app(WhatsappIngestionService::class);

        foreach (['baileys_qr', 'baileys_qr_history'] as $provider) {
            try {
                $service->ingest($company, [
                    'provider' => $provider,
                    'phone' => '5511999999999',
                    'direction' => 'inbound',
                ]);
                $this->fail("O provider legado {$provider} deveria ser rejeitado.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Provider de ingestão inválido', $exception->getMessage());
            }
        }

        foreach (['baileys_qr', 'baileys_qr_history', 'whatsapp_qr'] as $source) {
            try {
                $service->ingest($company, [
                    'provider' => 'fake',
                    'source' => $source,
                    'phone' => '5511999999999',
                    'direction' => 'inbound',
                ]);
                $this->fail("A origem legada {$source} deveria ser rejeitada.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('apenas histórica', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_lid_identifier_is_rejected_without_rewriting_historical_lead(): void
    {
        $company = Company::create(['name' => 'History Company', 'slug' => 'history-company']);
        $historicalLead = Lead::create([
            'company_id' => $company->id,
            'phone_e164' => 'lid:123456789',
            'source' => 'whatsapp_qr',
            'source_method' => 'auto',
            'metadata' => [],
        ]);

        try {
            app(WhatsappIngestionService::class)->ingest($company, [
                'provider' => 'fake',
                'phone' => 'lid:987654321',
                'direction' => 'inbound',
            ]);
            $this->fail('Um identificador lid:* novo deveria ser rejeitado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
        }

        $historicalLead->refresh();
        $this->assertSame('lid:123456789', $historicalLead->phone_e164);
        $this->assertSame('whatsapp_qr', $historicalLead->source);

        app(LeadSourceService::class)->applyAutoSourceFromReentry($historicalLead, 'instagram');
        $historicalLead->refresh();
        $this->assertSame('whatsapp_qr', $historicalLead->source);
    }

    public function test_legacy_http_routes_are_absent(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri())->all();

        $this->assertNotContains('api/v1/webhooks/whatsapp/baileys', $uris);
        $this->assertNotContains('api/v1/whatsapp/qr/start', $uris);
        $this->assertNotContains('api/v1/whatsapp/qr/status', $uris);
        $this->assertNotContains('api/v1/whatsapp/qr/logout', $uris);
    }
}
