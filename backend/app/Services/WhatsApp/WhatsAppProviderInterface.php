<?php

namespace App\Services\WhatsApp;

interface WhatsAppProviderInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function sendTextMessage(string $toPhone, string $body, array $context = []): WhatsAppSendResult;

    /**
     * @param array{type:string,path:string,mime_type:string,original_name:string,caption?:string|null} $media
     * @param array<string, mixed> $context
     */
    public function sendMediaMessage(string $toPhone, array $media, array $context = []): WhatsAppSendResult;
}
