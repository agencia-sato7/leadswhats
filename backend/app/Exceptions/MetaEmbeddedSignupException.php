<?php

namespace App\Exceptions;

use RuntimeException;

class MetaEmbeddedSignupException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
        public readonly int|string|null $metaErrorCode = null,
        public readonly ?string $metaErrorType = null,
        public readonly int|string|null $metaErrorSubcode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{code:int|string|null,type:string|null,error_subcode:int|string|null}|null
     */
    public function safeMetaError(): ?array
    {
        if ($this->metaErrorCode === null && $this->metaErrorType === null && $this->metaErrorSubcode === null) {
            return null;
        }

        return [
            'code' => $this->metaErrorCode,
            'type' => $this->metaErrorType,
            'error_subcode' => $this->metaErrorSubcode,
        ];
    }
}
