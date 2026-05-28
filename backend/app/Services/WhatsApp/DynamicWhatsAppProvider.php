<?php

namespace App\Services\WhatsApp;

use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;

class DynamicWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(
        private readonly MetaCloudWhatsAppProvider $metaCloudProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function sendTextMessage(string $toPhone, string $body, array $context = []): WhatsAppSendResult
    {
        $companyId = isset($context["company_id"]) ? (int) $context["company_id"] : 0;
        if ($companyId <= 0) {
            return WhatsAppSendResult::failure(
                provider: "dynamic",
                errorCode: "whatsapp_disconnected",
                errorMessage: "WhatsApp integration not configured (missing company ID)."
            );
        }

        $integration = CompanyWhatsAppIntegration::query()
            ->where("company_id", $companyId)
            ->first();

        if ($integration && $integration->status === CompanyWhatsAppIntegrationService::STATUS_CONFIGURED) {
            if ($integration->provider === CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD) {
                return $this->metaCloudProvider->sendTextMessage($toPhone, $body, $context);
            }
        }

        return WhatsAppSendResult::failure(
            provider: "dynamic",
            errorCode: "whatsapp_disconnected",
            errorMessage: "WhatsApp integration not configured or disconnected for this company."
        );
    }
}
