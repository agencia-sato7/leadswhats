<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\WhatsappIngestionService;
use App\Services\WhatsApp\MetaWebhookIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class WhatsappWebhookController extends Controller
{
    public function ingest(Request $request, WhatsappIngestionService $service): JsonResponse
    {
        if ((string) config('app.env') === 'production' || config('whatsapp.provider') !== 'fake') {
            abort(404);
        }

        $validated = $request->validate([
            'company_slug' => ['required', 'string'],
            'phone' => ['required', 'string'],
            'direction' => ['required', 'in:inbound,outbound'],
            'provider' => ['nullable', 'string', Rule::in(['fake'])],
            'channel' => ['nullable', 'in:text,audio'],
            'body' => ['nullable', 'string'],
            'audio_transcript' => ['nullable', 'string'],
            'source' => ['nullable', 'string', Rule::notIn(['baileys_qr', 'baileys_qr_history', 'whatsapp_qr'])],
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

        $validated['provider'] = 'fake';
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

    public function ingestMeta(Request $request, MetaWebhookIngestionService $service): JsonResponse
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

        return response()->json([
            'message' => 'Meta webhook processed.',
            'data' => $service->process($entries),
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
            ->where('status', CompanyWhatsAppIntegrationService::STATUS_CONFIGURED)
            ->whereNotNull('webhook_verify_token')
            ->where('webhook_verify_token', $providedToken)
            ->get()
            ->contains(fn (CompanyWhatsAppIntegration $integration): bool => CompanyWhatsAppIntegrationService::isConfigured($integration));
    }
}
