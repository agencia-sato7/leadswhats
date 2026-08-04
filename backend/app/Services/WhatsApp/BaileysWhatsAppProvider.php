<?php

namespace App\Services\WhatsApp;

use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use Illuminate\Support\Facades\Http;

class BaileysWhatsAppProvider implements WhatsAppProviderInterface
{
    private const PROVIDER_NAME = "baileys_qr";

    /**
     * @param array<string, mixed> $context
     */
    public function sendTextMessage(string $toPhone, string $body, array $context = []): WhatsAppSendResult
    {
        $companyId = isset($context["company_id"]) ? (int) $context["company_id"] : 0;
        if ($companyId <= 0) {
            return WhatsAppSendResult::failure(
                provider: self::PROVIDER_NAME,
                errorCode: "missing_company_id",
                errorMessage: "company_id is required in provider context.",
            );
        }

        $integration = CompanyWhatsAppIntegration::query()
            ->where("company_id", $companyId)
            ->first();

        if (!$integration || $integration->integration_type !== "baileys_qr") {
            return WhatsAppSendResult::failure(
                provider: self::PROVIDER_NAME,
                errorCode: "integration_not_found",
                errorMessage: "Baileys QR integration not found for company.",
            );
        }

        if ($integration->session_status !== "connected") {
            return WhatsAppSendResult::failure(
                provider: self::PROVIDER_NAME,
                errorCode: "session_not_connected",
                errorMessage: "WhatsApp QR session is not connected for this company.",
            );
        }

        $serviceUrl = rtrim((string) config("whatsapp.baileys_service_url", "http://whatsapp-qr-service:3001"), "/");
        $endpoint = $serviceUrl . "/api/send-message";

        $response = Http::timeout(15)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, [
                "company_id" => $companyId,
                "to" => $toPhone,
                "body" => $body,
            ]);

        $raw = $response->json();

        if (!$response->successful()) {
            $errorMessage = "Baileys service request failed.";
            $errorCode = "baileys_service_error";

            if (is_array($raw)) {
                $apiError = data_get($raw, "error");
                $errorMessage = is_string($apiError) && trim($apiError) !== "" ? $apiError : $errorMessage;
                $errorCode = "http_" . $response->status();
            }

            return WhatsAppSendResult::failure(
                provider: self::PROVIDER_NAME,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
                rawResponse: is_array($raw) ? $raw : ["status" => $response->status()],
            );
        }

        $externalMessageId = is_array($raw) ? (string) (data_get($raw, "data.external_message_id") ?? "") : "";

        return WhatsAppSendResult::success(
            provider: self::PROVIDER_NAME,
            externalMessageId: $externalMessageId !== "" ? $externalMessageId : null,
            rawResponse: is_array($raw) ? $raw : null,
        );
    }
}