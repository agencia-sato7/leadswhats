<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\Domain\LidResolutionService;
use App\Services\WhatsappIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BaileysWebhookController extends Controller
{
    public function ingest(
        Request $request,
        WhatsappIngestionService $ingestionService,
        LidResolutionService $lidResolutionService
    ): JsonResponse {
        $validated = $request->validate([
            "company_id" => ["required", "integer"],
            "event" => ["required", "string", "in:qr_updated,connected,disconnected,message_received,history_synced,lid_resolved"],
            "data" => ["nullable", "array"],
        ]);

        $companyId = (int) $validated["company_id"];
        $event = (string) $validated["event"];
        $data = $validated["data"] ?? [];

        $integration = CompanyWhatsAppIntegration::query()
            ->where("company_id", $companyId)
            ->first();

        if (!$integration) {
            return response()->json(["message" => "Integration not found."], 404);
        }

        switch ($event) {
            case "qr_updated":
                $integration->qr_code_base64 = $data["qr_code"] ?? $integration->qr_code_base64;
                $integration->session_status = CompanyWhatsAppIntegrationService::SESSION_STATUS_CONNECTING;
                $integration->save();
                break;

            case "connected":
                $integration->session_status = CompanyWhatsAppIntegrationService::SESSION_STATUS_CONNECTED;
                $integration->baileys_phone = $data["phone"] ?? $integration->baileys_phone;
                $integration->status = CompanyWhatsAppIntegrationService::STATUS_CONFIGURED;
                $integration->qr_code_base64 = null;
                if (!$integration->connected_at) {
                    $integration->connected_at = now();
                }
                $integration->last_error = null;
                $integration->save();
                break;

            case "disconnected":
                $integration->session_status = CompanyWhatsAppIntegrationService::SESSION_STATUS_DISCONNECTED;
                $integration->status = CompanyWhatsAppIntegrationService::STATUS_NOT_CONFIGURED;
                $integration->connected_at = null;
                $integration->last_error = "Desconectado: " . ($data["reason"] ?? "motivo desconhecido");
                $integration->save();
                break;

            case "lid_resolved":
                $company = $integration->company;
                if (!$company) {
                    return response()->json(["message" => "Company not found."], 404);
                }

                $lid = (string) ($data["lid"] ?? "");
                $phone = (string) ($data["phone"] ?? "");

                if ($lid === "" || $phone === "") {
                    return response()->json(["message" => "Incomplete lid resolution data ignored."], 200);
                }

                $lidResolutionService->resolve($company, $lid, $phone);
                break;

            case "message_received":
                $company = $integration->company;
                if (!$company) {
                    return response()->json(["message" => "Company not found."], 404);
                }

                $phone = (string) ($data["phone"] ?? "");
                $body = (string) ($data["body"] ?? "");
                $direction = (string) ($data["direction"] ?? "inbound");
                if (!in_array($direction, ["inbound", "outbound"], true)) {
                    $direction = "inbound";
                }
                $externalMessageId = (string) ($data["external_message_id"] ?? "");
                $sentAt = (string) ($data["sent_at"] ?? now()->toISOString());
                $rawPayload = (string) ($data["raw_payload"] ?? "");

                if ($phone === "" || $body === "") {
                    return response()->json(["message" => "Incomplete message data ignored."], 200);
                }

                $ingestionService->ingest($company, [
                    "provider" => "baileys_qr",
                    "phone" => $phone,
                    "direction" => $direction,
                    "channel" => "text",
                    "body" => $body,
                    "source" => "whatsapp_qr",
                    "external_message_id" => $externalMessageId !== "" ? $externalMessageId : null,
                    "sent_at" => $sentAt,
                    "raw_payload" => $rawPayload !== "" ? json_decode($rawPayload, true) : null,
                ]);
                break;

            case "history_synced":
                $company = $integration->company;
                if (!$company) {
                    return response()->json(["message" => "Company not found."], 404);
                }

                // Um lote de até 500 mensagens (cada uma com resolução de lead/conversa e
                // possíveis chamadas de IA) pode passar do limite padrão de 30s do PHP e
                // matar a requisição no meio, perdendo o restante do lote. Isso já aconteceu
                // em produção com um histórico grande — estende o limite só para esta rota.
                set_time_limit(180);

                $historyMessages = is_array($data["messages"] ?? null) ? $data["messages"] : [];

                $maxBatchSize = 500;
                if (count($historyMessages) > $maxBatchSize) {
                    Log::warning("Baileys history_synced batch truncated", [
                        "company_id" => $company->id,
                        "received" => count($historyMessages),
                        "kept" => $maxBatchSize,
                    ]);
                    $historyMessages = array_slice($historyMessages, 0, $maxBatchSize);
                }

                // Ordena por sent_at para preservar as premissas cronológicas do
                // WhatsappIngestionService (tempo de primeira resposta, detecção de
                // resgate, classificação de lead repetido) — o lote do Baileys não
                // garante ordem.
                usort($historyMessages, function ($a, $b) {
                    $aTime = strtotime((string) ($a["sent_at"] ?? "")) ?: 0;
                    $bTime = strtotime((string) ($b["sent_at"] ?? "")) ?: 0;
                    return $aTime <=> $bTime;
                });

                $ingested = 0;
                foreach ($historyMessages as $historyMessage) {
                    if (!is_array($historyMessage)) {
                        continue;
                    }

                    $phone = (string) ($historyMessage["phone"] ?? "");
                    $body = (string) ($historyMessage["body"] ?? "");

                    if ($phone === "" || $body === "") {
                        Log::warning("Baileys history_synced item skipped: incomplete data", [
                            "company_id" => $company->id,
                            "external_message_id" => $historyMessage["external_message_id"] ?? null,
                        ]);
                        continue;
                    }

                    $direction = (string) ($historyMessage["direction"] ?? "inbound");
                    if (!in_array($direction, ["inbound", "outbound"], true)) {
                        $direction = "inbound";
                    }
                    $externalMessageId = (string) ($historyMessage["external_message_id"] ?? "");
                    $sentAt = (string) ($historyMessage["sent_at"] ?? now()->toISOString());
                    $rawPayload = (string) ($historyMessage["raw_payload"] ?? "");

                    try {
                        $ingestionService->ingest($company, [
                            "provider" => "baileys_qr_history",
                            "phone" => $phone,
                            "direction" => $direction,
                            "channel" => "text",
                            "body" => $body,
                            "source" => "whatsapp_qr",
                            "external_message_id" => $externalMessageId !== "" ? $externalMessageId : null,
                            "sent_at" => $sentAt,
                            "raw_payload" => $rawPayload !== "" ? json_decode($rawPayload, true) : null,
                        ]);
                        $ingested++;
                    } catch (\Throwable $e) {
                        Log::error("Baileys history_synced item failed to ingest", [
                            "company_id" => $company->id,
                            "external_message_id" => $externalMessageId !== "" ? $externalMessageId : null,
                            "error" => $e->getMessage(),
                        ]);
                    }
                }

                Log::info("Baileys history_synced batch processed", [
                    "company_id" => $company->id,
                    "received" => count($historyMessages),
                    "ingested" => $ingested,
                ]);
                break;
        }

        return response()->json(["message" => "Event processed."]);
    }
}