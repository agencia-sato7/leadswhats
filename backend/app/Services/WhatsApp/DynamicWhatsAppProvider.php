<?php

namespace App\Services\WhatsApp;

use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;

class DynamicWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(
        private readonly MetaCloudWhatsAppProvider $metaCloudProvider,
        private readonly BaileysWhatsAppProvider $baileysProvider,
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

        if (!$integration) {
            return WhatsAppSendResult::failure(
                provider: "dynamic",
                errorCode: "whatsapp_disconnected",
                errorMessage: "WhatsApp integration not configured or disconnected for this company."
            );
        }

        // Route based on integration type
        if ($integration->integration_type === CompanyWhatsAppIntegrationService::INTEGRATION_TYPE_BAILEYS_QR) {
            if ($integration->session_status === "connected") {
                return $this->baileysProvider->sendTextMessage($toPhone, $body, $context);
            }

            return WhatsAppSendResult::failure(
                provider: "dynamic",
                errorCode: "whatsapp_disconnected",
                errorMessage: "WhatsApp QR session is not connected for this company."
            );
        }

        // Default: Meta Cloud
        if ($integration->status === CompanyWhatsAppIntegrationService::STATUS_CONFIGURED) {
            return $this->metaCloudProvider->sendTextMessage($toPhone, $body, $context);
        }

        return WhatsAppSendResult::failure(
            provider: "dynamic",
            errorCode: "whatsapp_disconnected",
            errorMessage: "WhatsApp integration not configured or disconnected for this company."
        );
    }
}
