<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\WhatsappIngestionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsappWebhookController extends Controller
{
    public function ingest(Request $request, WhatsappIngestionService $service): JsonResponse
    {
        $validated = $request->validate([
            'company_slug' => ['required', 'string'],
            'phone' => ['required', 'string'],
            'direction' => ['required', 'in:inbound,outbound'],
            'provider' => ['nullable', 'string', 'max:60'],
            'channel' => ['nullable', 'in:text,audio'],
            'body' => ['nullable', 'string'],
            'audio_transcript' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'lead_name' => ['nullable', 'string'],
            'external_message_id' => ['nullable', 'string'],
            'sent_at' => ['nullable', 'date'],
            'owner_user_id' => ['nullable', 'integer'],
        ]);

        $company = Company::where('slug', $validated['company_slug'])->first();

        if (!$company) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }

        $providedToken = (string) $request->header('X-Webhook-Token', '');
        $configuredToken = CompanyBusinessSetting::query()
            ->where('company_id', $company->id)
            ->value('webhook_token');

        if (filled($configuredToken)) {
            if ($providedToken === '' || !hash_equals((string) $configuredToken, $providedToken)) {
                return response()->json(['message' => 'Webhook token inválido.'], 401);
            }
        } elseif ((string) config('app.env') === 'production') {
            return response()->json(['message' => 'Webhook token não configurado.'], 401);
        }

        $validated['raw_payload'] = $request->all();

        $result = $service->ingest($company, $validated);

        return response()->json([
            'message' => $result['duplicated'] ? 'Mensagem já processada (idempotente).' : 'Mensagem ingerida com sucesso.',
            'data' => $result,
        ]);
    }

    public function verifyMeta(Request $request): Response
    {
        $query = $request->query();
        $mode = (string) ($query['hub.mode'] ?? $query['hub_mode'] ?? '');
        $verifyToken = (string) ($query['hub.verify_token'] ?? $query['hub_verify_token'] ?? '');
        $challenge = (string) ($query['hub.challenge'] ?? $query['hub_challenge'] ?? '');

        if ($mode !== 'subscribe' || $challenge === '' || !$this->isValidMetaVerifyToken($verifyToken)) {
            return response('Forbidden', 403)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function ingestMeta(Request $request, WhatsappIngestionService $service): JsonResponse
    {
        $signatureHeader = (string) $request->header('X-Hub-Signature-256', '');
        $appSecret = trim((string) config('whatsapp.cloud_app_secret', ''));
        $isProduction = (string) config('app.env') === 'production';

        $shouldValidate = $isProduction || $appSecret !== '';

        if ($shouldValidate) {
            if ($signatureHeader === '') {
                return response()->json(['message' => 'Missing signature header.'], 403);
            }

            if (!str_starts_with($signatureHeader, 'sha256=')) {
                return response()->json(['message' => 'Invalid signature format.'], 403);
            }

            if ($appSecret === '') {
                return response()->json(['message' => 'App secret not configured on backend.'], 403);
            }

            $signature = substr($signatureHeader, 7); // Remove 'sha256='
            $payload = $request->getContent();
            $calculated = hash_hmac('sha256', $payload, $appSecret);

            if (!hash_equals($calculated, $signature)) {
                return response()->json(['message' => 'Invalid signature.'], 403);
            }
        }

        $entries = $request->input('entry');
        if (!is_array($entries) || $entries === []) {
            return response()->json([
                'message' => 'Meta webhook ignored: empty payload.',
                'data' => [
                    'processed' => 0,
                    'ignored' => 1,
                    'unmatched' => 0,
                    'duplicated' => 0,
                ],
            ]);
        }

        $processed = 0;
        $ignored = 0;
        $unmatched = 0;
        $duplicated = 0;

        foreach ($entries as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = (array) data_get($change, 'value', []);
                $phoneNumberId = trim((string) data_get($value, 'metadata.phone_number_id', ''));

                if ($phoneNumberId === '') {
                    $ignored++;
                    continue;
                }

                $integration = CompanyWhatsAppIntegration::query()
                    ->where('provider', CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD)
                    ->where('phone_number_id', $phoneNumberId)
                    ->first();

                if (!$integration || !$integration->company_id) {
                    $unmatched++;
                    continue;
                }

                $company = Company::query()->find($integration->company_id);
                if (!$company) {
                    $unmatched++;
                    continue;
                }

                $messages = data_get($value, 'messages');
                if (!is_array($messages) || $messages === []) {
                    $ignored++;
                    continue;
                }

                foreach ($messages as $message) {
                    $type = (string) data_get($message, 'type', '');
                    if ($type !== 'text') {
                        $ignored++;
                        continue;
                    }

                    $phone = trim((string) data_get($message, 'from', ''));
                    $body = (string) data_get($message, 'text.body', '');
                    if ($phone === '' || trim($body) === '') {
                        $ignored++;
                        continue;
                    }

                    $externalMessageId = trim((string) data_get($message, 'id', ''));
                    $timestamp = data_get($message, 'timestamp');
                    $sentAt = is_numeric($timestamp)
                        ? Carbon::createFromTimestampUTC((int) $timestamp)->toISOString()
                        : null;

                    $result = $service->ingest($company, [
                        'provider' => CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD,
                        'phone' => $phone,
                        'direction' => 'inbound',
                        'channel' => 'text',
                        'body' => $body,
                        'source' => 'meta_cloud',
                        'external_message_id' => $externalMessageId !== '' ? $externalMessageId : null,
                        'sent_at' => $sentAt,
                        'raw_payload' => [
                            'meta_value' => $value,
                            'meta_message' => $message,
                        ],
                    ]);

                    if ((bool) ($result['duplicated'] ?? false)) {
                        $duplicated++;
                    } else {
                        $processed++;
                    }
                }
            }
        }

        return response()->json([
            'message' => 'Meta webhook processed.',
            'data' => [
                'processed' => $processed,
                'ignored' => $ignored,
                'unmatched' => $unmatched,
                'duplicated' => $duplicated,
            ],
        ]);
    }

    private function isValidMetaVerifyToken(string $providedToken): bool
    {
        if ($providedToken === '') {
            return false;
        }

        $globalToken = trim((string) config('whatsapp.cloud_webhook_verify_token', ''));
        if ($globalToken !== '' && hash_equals($globalToken, $providedToken)) {
            return true;
        }

        return CompanyWhatsAppIntegration::query()
            ->where('provider', CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD)
            ->whereNotNull('webhook_verify_token')
            ->where('webhook_verify_token', $providedToken)
            ->exists();
    }
}
