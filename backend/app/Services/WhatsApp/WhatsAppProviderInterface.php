<?php

namespace App\Services\WhatsApp;

interface WhatsAppProviderInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function sendTextMessage(string $toPhone, string $body, array $context = []): WhatsAppSendResult;
}
