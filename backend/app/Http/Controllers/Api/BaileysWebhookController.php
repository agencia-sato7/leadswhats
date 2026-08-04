<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use App\Services\WhatsappIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BaileysWebhookController extends Controller
{
    public function ingest(Request $request, WhatsappIngestionService $ingestionService): JsonResponse
    {
        $validated = $request->validate([
            "company_id" => ["required", "integer"],
            "event" => ["required", "string", "in:qr_updated,connected,disconnected,message_received"],
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

            case "message_received":
                $company = $integration->company;
                if (!$company) {
                    return response()->json(["message" => "Company not found."], 404);
                }

                $phone = (string) ($data["phone"] ?? "");
                $body = (string) ($data["body"] ?? "");
                $externalMessageId = (string) ($data["external_message_id"] ?? "");
                $sentAt = (string) ($data["sent_at"] ?? now()->toISOString());
                $rawPayload = (string) ($data["raw_payload"] ?? "");

                if ($phone === "" || $body === "") {
                    return response()->json(["message" => "Incomplete message data ignored."], 200);
                }

                $ingestionService->ingest($company, [
                    "provider" => "baileys_qr",
                    "phone" => $phone,
                    "direction" => "inbound",
                    "channel" => "text",
                    "body" => $body,
                    "source" => "whatsapp_qr",
                    "external_message_id" => $externalMessageId !== "" ? $externalMessageId : null,
                    "sent_at" => $sentAt,
                    "raw_payload" => $rawPayload !== "" ? json_decode($rawPayload, true) : null,
                ]);
                break;
        }

        return response()->json(["message" => "Event processed."]);
    }
}