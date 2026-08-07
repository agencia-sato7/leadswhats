<?php

namespace App\Services\Domain;

use Illuminate\Support\Facades\Log;

class PhoneNormalizationService
{
    /**
     * Normaliza um telefone recebido do provedor de WhatsApp.
     *
     * Nunca fabrica um número: um LID (identificador interno de privacidade do
     * WhatsApp, não um MSISDN) não pode virar um telefone só porque tem dígitos.
     * Quando o valor recebido já vem marcado como "lid:..." (Baileys não conseguiu
     * mapear o LID para o número real) ou não tem uma contagem de dígitos plausível
     * para um telefone BR, ele é preservado como "não identificado" em vez de
     * receber um "+55" fabricado na frente.
     */
    public function normalize(string $phone): string
    {
        if (str_starts_with($phone, "lid:")) {
            return $phone;
        }

        $digits = preg_replace("/\\D+/", "", $phone);
        $length = strlen($digits);

        // BR: 10-11 dígitos locais (DDD + número), ou 12-13 já com o 55 na frente.
        $isValidWithCountryCode = in_array($length, [12, 13], true) && str_starts_with($digits, "55");
        $isValidWithoutCountryCode = in_array($length, [10, 11], true);

        if (!$isValidWithCountryCode && !$isValidWithoutCountryCode) {
            Log::warning("Telefone recebido não parece válido, tratando como não identificado: {$phone} (dígitos: {$digits})");
            return "lid:" . $digits;
        }

        if ($isValidWithCountryCode) {
            return "+" . $digits;
        }

        return "+55" . $digits;
    }
}
