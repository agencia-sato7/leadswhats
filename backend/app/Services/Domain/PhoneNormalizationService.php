<?php

namespace App\Services\Domain;

use Illuminate\Validation\ValidationException;

class PhoneNormalizationService
{
    /**
     * Normaliza um telefone recebido do provedor de WhatsApp.
     *
     * Nunca fabrica um número: somente telefones BR plausíveis são aceitos para
     * novas gravações. Identificadores históricos que não são MSISDN permanecem
     * preservados no banco, mas não podem entrar novamente pela ingestão.
     */
    public function normalize(string $phone): string
    {
        if (str_starts_with($phone, "lid:")) {
            throw ValidationException::withMessages([
                'phone' => 'Identificadores lid:* são apenas históricos e não podem ser ingeridos.',
            ]);
        }

        $digits = preg_replace("/\\D+/", "", $phone);
        $length = strlen($digits);

        // BR: 10-11 dígitos locais (DDD + número), ou 12-13 já com o 55 na frente.
        $isValidWithCountryCode = in_array($length, [12, 13], true) && str_starts_with($digits, "55");
        $isValidWithoutCountryCode = in_array($length, [10, 11], true);

        if (!$isValidWithCountryCode && !$isValidWithoutCountryCode) {
            throw ValidationException::withMessages([
                'phone' => 'O telefone informado não possui um formato brasileiro válido.',
            ]);
        }

        if ($isValidWithCountryCode) {
            return "+" . $digits;
        }

        return "+55" . $digits;
    }
}
