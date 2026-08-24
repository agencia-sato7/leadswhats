<?php

namespace App\Services\WhatsApp;

use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use Illuminate\Support\Facades\Http;

class MetaCloudWhatsAppProvider implements WhatsAppProviderInterface
{
    private const PROVIDER_NAME = "meta_cloud";

    /**
     * @param array<string, mixed> $context
     */
    public function sendTextMessage(string $toPhone, string $body, array $context = []): WhatsAppSendResult
    {
        $toPhone = $this->normalizeRecipient($toPhone);
        if ($toPhone === null) {
            return WhatsAppSendResult::failure(self::PROVIDER_NAME, 'invalid_recipient', 'Número de destino inválido.');
        }
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
            ->where("provider", CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD)
            ->first();

        if (!$integration) {
            return WhatsAppSendResult::failure(
                provider: self::PROVIDER_NAME,
                errorCode: "integration_not_found",
                errorMessage: "WhatsApp integration not found for company.",
            );
        }

        if (!CompanyWhatsAppIntegrationService::isConfigured($integration)) {
            return WhatsAppSendResult::failure(
                provider: self::PROVIDER_NAME,
                errorCode: "integration_not_configured",
                errorMessage: "WhatsApp integration is not configured for this company.",
            );
        }

        $accessToken = is_string($integration->access_token_encrypted) ? trim($integration->access_token_encrypted) : "";
        $phoneNumberId = is_string($integration->phone_number_id) ? trim($integration->phone_number_id) : "";

        if ($accessToken === "" || $phoneNumberId === "") {
            return WhatsAppSendResult::failure(
                provider: self::PROVIDER_NAME,
                errorCode: "integration_incomplete",
                errorMessage: "Missing required Meta Cloud credentials for this company.",
            );
        }

        $apiVersion = (string) config("whatsapp.cloud_api_version", "v21.0");
        $endpoint = sprintf("https://graph.facebook.com/%s/%s/messages", trim($apiVersion, "/"), $phoneNumberId);

        $payload = [
            "messaging_product" => "whatsapp",
            "to" => $toPhone,
            "type" => "text",
            "text" => [
                "body" => $body,
            ],
        ];

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, $payload);

        $raw = $response->json();
        if (!$response->successful()) {
            $errorCode = null;
            $errorMessage = "Meta Cloud API request failed.";

            if (is_array($raw)) {
                $apiErrorCode = data_get($raw, "error.code");
                $apiErrorMessage = data_get($raw, "error.message");
                $errorCode = $apiErrorCode !== null ? (string) $apiErrorCode : "http_" . $response->status();
                $errorMessage = is_string($apiErrorMessage) && trim($apiErrorMessage) !== ""
                    ? $apiErrorMessage
                    : $errorMessage;
            } else {
                $errorCode = "http_" . $response->status();
            }

            return WhatsAppSendResult::failure(
                provider: self::PROVIDER_NAME,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
                rawResponse: is_array($raw) ? $raw : ["status" => $response->status()],
            );
        }

        $externalMessageId = is_array($raw) ? (string) (data_get($raw, "messages.0.id") ?? "") : "";

        return WhatsAppSendResult::success(
            provider: self::PROVIDER_NAME,
            externalMessageId: $externalMessageId !== "" ? $externalMessageId : null,
            rawResponse: is_array($raw) ? $raw : null,
        );
    }

    public function sendMediaMessage(string $toPhone, array $media, array $context = []): WhatsAppSendResult
    {
        $toPhone = $this->normalizeRecipient($toPhone);
        if ($toPhone === null) {
            return WhatsAppSendResult::failure(self::PROVIDER_NAME, 'invalid_recipient', 'Número de destino inválido.');
        }
        $credentials = $this->credentials($context);
        if ($credentials instanceof WhatsAppSendResult) return $credentials;

        [$accessToken, $phoneNumberId, $apiVersion] = $credentials;
        $upload = Http::withToken($accessToken)
            ->attach('file', fopen($media['path'], 'r'), $media['original_name'], ['Content-Type' => $media['mime_type']])
            ->post("https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/media", [
                'messaging_product' => 'whatsapp',
                'type' => $media['mime_type'],
            ]);

        if (! $upload->successful() || ! $upload->json('id')) {
            return WhatsAppSendResult::failure(self::PROVIDER_NAME, (string) ($upload->json('error.code') ?? 'media_upload_failed'), (string) ($upload->json('error.message') ?? 'Falha ao enviar mídia para a Meta.'));
        }

        $type = $media['type'];
        $content = ['id' => (string) $upload->json('id')];
        if (! empty($media['caption']) && in_array($type, ['image', 'video', 'document'], true)) $content['caption'] = $media['caption'];
        if ($type === 'document') $content['filename'] = $media['original_name'];

        $response = Http::withToken($accessToken)->acceptJson()->asJson()
            ->post("https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp', 'to' => $toPhone, 'type' => $type, $type => $content,
            ]);

        if (! $response->successful()) {
            return WhatsAppSendResult::failure(self::PROVIDER_NAME, (string) ($response->json('error.code') ?? 'media_send_failed'), (string) ($response->json('error.message') ?? 'Falha ao enviar mídia pela Meta.'));
        }

        return WhatsAppSendResult::success(self::PROVIDER_NAME, $response->json('messages.0.id'), ['media_id' => $upload->json('id')]);
    }

    /** @return array{string,string,string}|WhatsAppSendResult */
    private function credentials(array $context): array|WhatsAppSendResult
    {
        $companyId = (int) ($context['company_id'] ?? 0);
        $integration = CompanyWhatsAppIntegration::query()->where('company_id', $companyId)->where('provider', CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD)->first();
        if (! CompanyWhatsAppIntegrationService::isConfigured($integration)) {
            return WhatsAppSendResult::failure(self::PROVIDER_NAME, 'integration_not_configured', 'WhatsApp integration is not configured for this company.');
        }
        return [trim((string) $integration->access_token_encrypted), trim((string) $integration->phone_number_id), trim((string) config('whatsapp.cloud_api_version', 'v21.0'), '/')];
    }

    private function normalizeRecipient(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }
}
