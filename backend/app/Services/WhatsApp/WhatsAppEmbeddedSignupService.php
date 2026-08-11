<?php

namespace App\Services\WhatsApp;

use App\Services\CompanyWhatsAppIntegrationService;

class WhatsAppEmbeddedSignupService
{
    public function __construct(
        private readonly MetaEmbeddedSignupTokenExchangeService $tokenExchange,
        private readonly CompanyWhatsAppIntegrationService $integrationService,
    ) {}

    /**
     * @param  array<string, mixed>  $embeddedSignupData
     * @return array<string, mixed>
     */
    public function complete(int $companyId, string $code, array $embeddedSignupData): array
    {
        $accessToken = $this->tokenExchange->exchange($code);

        $integration = $this->integrationService->completeEmbeddedSignupForCompany(
            $companyId,
            $embeddedSignupData,
            $accessToken,
        );

        unset($integration['webhook_verify_token']);

        return $integration;
    }
}
