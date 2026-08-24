<?php

namespace App\Services\WhatsApp;

use RuntimeException;

class FakeWhatsAppProvider implements WhatsAppProviderInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function sendTextMessage(string $toPhone, string $body, array $context = []): WhatsAppSendResult
    {
        if ((string) config('app.env') === 'production') {
            throw new RuntimeException('O provider fake não pode ser executado em production.');
        }

        $fingerprint = hash("sha256", json_encode([
            "to_phone" => $toPhone,
            "body" => $body,
            "context" => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $externalMessageId = "fake-" . substr($fingerprint, 0, 24);

        return WhatsAppSendResult::success(
            provider: "fake",
            externalMessageId: $externalMessageId,
            rawResponse: [
                "simulated" => true,
                "to_phone" => $toPhone,
                "message_preview" => mb_substr($body, 0, 120),
            ],
        );
    }

    public function sendMediaMessage(string $toPhone, array $media, array $context = []): WhatsAppSendResult
    {
        if ((string) config('app.env') === 'production') {
            throw new RuntimeException('O provider fake não pode ser executado em production.');
        }

        return WhatsAppSendResult::success(
            provider: 'fake',
            externalMessageId: 'fake-media-'.substr(hash('sha256', json_encode([$toPhone, $media, $context])), 0, 20),
            rawResponse: ['simulated' => true, 'media_type' => $media['type']],
        );
    }
}
