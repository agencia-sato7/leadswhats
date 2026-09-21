<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\WhatsApp\MetaWebhookIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Importação do histórico do WhatsApp Business app (webhook `history`).
 *
 * A Meta recomenda processar esse payload de forma assíncrona, pois uma única
 * entrega pode carregar milhares de mensagens dos últimos 180 dias.
 */
class ProcessMetaCoexistenceHistory implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly int $companyId,
        public readonly int $integrationId,
        public readonly array $value,
    ) {}

    public function handle(MetaWebhookIngestionService $service): void
    {
        $company = Company::find($this->companyId);
        $integration = CompanyWhatsAppIntegration::find($this->integrationId);
        if (! $company || ! $integration || $integration->company_id !== $company->id
            || ! \App\Services\CompanyWhatsAppIntegrationService::isConfigured($integration)) {
            return;
        }

        $service->ingestHistoryValue($company, $integration, $this->value);
    }
}