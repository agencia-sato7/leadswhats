<?php

namespace App\Services\WhatsApp;

class WhatsAppSendResult
{
    /**
     * @param array<string, mixed>|null $rawResponse
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $provider,
        public readonly ?string $externalMessageId = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $rawResponse
     */
    public static function success(string $provider, ?string $externalMessageId = null, ?array $rawResponse = null): self
    {
        return new self(
            success: true,
            provider: $provider,
            externalMessageId: $externalMessageId,
            rawResponse: $rawResponse,
        );
    }

    /**
     * @param array<string, mixed>|null $rawResponse
     */
    public static function failure(string $provider, ?string $errorCode = null, ?string $errorMessage = null, ?array $rawResponse = null): self
    {
        return new self(
            success: false,
            provider: $provider,
            rawResponse: $rawResponse,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }
}
