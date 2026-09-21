<?php

namespace App\Services\WhatsApp;

use App\Exceptions\MetaCoexistenceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Chamadas server-side exigidas pela Coexistência depois do Embedded Signup:
 * assinatura do app no WABA e sincronização de contatos/histórico do
 * WhatsApp Business app (smb_app_data).
 */
class MetaCoexistenceOnboardingService
{
    public function unsubscribeApp(string $wabaId, string $accessToken): void
    {
        $response = $this->send(
            method: 'DELETE',
            path: trim($wabaId, '/').'/subscribed_apps',
            accessToken: $accessToken,
            payload: [],
            operation: 'unsubscribe_app',
            failureMessage: 'Não foi possível desconectar o aplicativo da conta WhatsApp na Meta.',
        );

        if ($response->json('success') !== true) {
            throw new MetaCoexistenceException('A Meta não confirmou a desconexão. Tente novamente.', 502);
        }
    }

    public const SYNC_TYPE_CONTACTS = 'contacts';

    public const SYNC_TYPE_HISTORY = 'history';

    /**
     * @return array<string, mixed>
     */
    public function subscribeApp(string $wabaId, string $accessToken): array
    {
        $response = $this->send(
            method: 'POST',
            path: trim($wabaId, '/').'/subscribed_apps',
            accessToken: $accessToken,
            payload: [],
            operation: 'subscribed_apps',
            failureMessage: 'Não foi possível assinar o aplicativo no WhatsApp Business Account.',
        );

        return (array) $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function requestDataSync(string $phoneNumberId, string $accessToken, string $syncType): array
    {
        $response = $this->send(
            method: 'POST',
            path: trim($phoneNumberId, '/').'/smb_app_data',
            accessToken: $accessToken,
            payload: [
                'messaging_product' => 'whatsapp',
                'sync_type' => $syncType,
            ],
            operation: 'smb_app_data_request:'.$syncType,
            failureMessage: 'Não foi possível solicitar a sincronização dos dados do WhatsApp Business app.',
        );

        return (array) $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchDataSyncStatus(string $phoneNumberId, string $accessToken): array
    {
        $response = $this->send(
            method: 'GET',
            path: trim($phoneNumberId, '/').'/smb_app_data',
            accessToken: $accessToken,
            payload: [],
            operation: 'smb_app_data_status',
            failureMessage: 'Não foi possível consultar a sincronização dos dados do WhatsApp Business app.',
        );

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(
        string $method,
        string $path,
        string $accessToken,
        array $payload,
        string $operation,
        string $failureMessage,
    ): Response {
        if (trim($accessToken) === '') {
            throw new MetaCoexistenceException(
                'A conexão com o WhatsApp não possui token de acesso válido.',
                409,
            );
        }

        $apiVersion = trim((string) config('whatsapp.meta_graph_api_version', 'v25.0'), '/');
        $url = "https://graph.facebook.com/{$apiVersion}/".ltrim($path, '/');

        // Allowlist de metadados: o access token nunca entra no log.
        Log::info('Meta Coexistence API request metadata.', [
            'operation' => $operation,
            'url' => $url,
            'method' => $method,
            'parameter_names' => array_keys($payload),
            'graph_api_version' => $apiVersion,
        ]);

        $request = Http::acceptJson()
            ->withToken($accessToken)
            ->connectTimeout(max((int) config('whatsapp.meta_connect_timeout_seconds', 5), 1))
            ->timeout(max((int) config('whatsapp.meta_timeout_seconds', 15), 1));

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $payload),
                'DELETE' => $request->delete($url),
                default => $request->asJson()->post($url, $payload),
            };
        } catch (ConnectionException) {
            throw new MetaCoexistenceException(
                'Não foi possível concluir a comunicação segura com a Meta.',
            );
        }

        if (! $response->successful()) {
            $errorCode = $this->scalarOrNull($response->json('error.code'));
            $errorType = $response->json('error.type');
            $errorSubcode = $this->scalarOrNull($response->json('error.error_subcode'));

            Log::warning('Meta Coexistence API request failed.', [
                'operation' => $operation,
                'url' => $url,
                'http_status' => $response->status(),
                'error_code' => $errorCode,
                'error_type' => is_string($errorType) ? $errorType : null,
                'error_subcode' => $errorSubcode,
            ]);

            throw new MetaCoexistenceException(
                $failureMessage,
                502,
                $errorCode,
                is_string($errorType) ? $errorType : null,
                $errorSubcode,
            );
        }

        return $response;
    }

    private function scalarOrNull(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}