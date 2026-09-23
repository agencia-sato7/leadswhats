<?php

namespace App\Services\WhatsApp;

use App\Exceptions\MetaCoexistenceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaCoexistenceTokenExchangeService
{
    /**
     * Troca o code temporário (TTL de 30s) devolvido pelo Embedded Signup de
     * Coexistência pelo token de acesso da Meta.
     *
     * @return array{access_token:string,expires_in:int|null}
     */
    public function exchange(string $code): array
    {
        $appId = trim((string) config('whatsapp.meta_app_id', ''));
        $appSecret = trim((string) config('whatsapp.meta_app_secret', ''));

        if ($appId === '' || $appSecret === '') {
            throw new MetaCoexistenceException(
                'As credenciais da Coexistência não estão configuradas no servidor.',
                503,
            );
        }

        $apiVersion = trim((string) config('whatsapp.meta_graph_api_version', 'v25.0'), '/');
        $endpoint = "https://graph.facebook.com/{$apiVersion}/oauth/access_token";
        $payload = [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ];

        // Instrumentação temporária: somente metadados em allowlist, sem valores
        // de code, client_secret, resposta OAuth ou access_token.
        Log::info('Meta Coexistence OAuth request metadata.', [
            'url' => $endpoint,
            'method' => 'POST',
            'content_type' => 'application/json',
            'parameter_names' => array_keys($payload),
            'redirect_uri' => null,
            'client_id' => $appId,
            'grant_type' => $payload['grant_type'],
            'graph_api_version' => $apiVersion,
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(max((int) config('whatsapp.meta_connect_timeout_seconds', 5), 1))
                ->timeout(max((int) config('whatsapp.meta_timeout_seconds', 15), 1))
                ->post($endpoint, $payload);
        } catch (ConnectionException) {
            throw new MetaCoexistenceException(
                'Não foi possível concluir a comunicação segura com a Meta.',
            );
        }

        if (! $response->successful()) {
            $errorCode = $this->scalarOrNull($response->json('error.code'));
            $errorType = $response->json('error.type');
            $errorSubcode = $this->scalarOrNull($response->json('error.error_subcode'));

            Log::warning('Meta Coexistence OAuth exchange failed.', [
                'http_status' => $response->status(),
                'error_code' => $errorCode,
                'error_type' => is_string($errorType) ? $errorType : null,
                'error_subcode' => $errorSubcode,
            ]);

            $message = (string) $errorSubcode === '36008'
                ? 'A Meta recusou a autorização por uma configuração de redirecionamento incompatível. Inicie uma nova conexão.'
                : 'A Meta não aceitou a conclusão da Coexistência.';

            throw new MetaCoexistenceException(
                $message,
                502,
                $errorCode,
                is_string($errorType) ? $errorType : null,
                $errorSubcode,
            );
        }

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw new MetaCoexistenceException(
                'A Meta retornou uma resposta inválida para a Coexistência.',
            );
        }

        $expiresIn = $response->json('expires_in');

        return [
            'access_token' => trim($accessToken),
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : null,
        ];
    }

    private function scalarOrNull(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}