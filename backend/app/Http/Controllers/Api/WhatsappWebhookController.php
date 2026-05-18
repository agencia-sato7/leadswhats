<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Services\WhatsappIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
