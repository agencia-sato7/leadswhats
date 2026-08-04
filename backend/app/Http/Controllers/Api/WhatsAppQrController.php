<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyWhatsAppIntegration;
use App\Services\CompanyWhatsAppIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppQrController extends Controller
{
    public function __construct(
        private readonly CompanyWhatsAppIntegrationService $integrationService,
    ) {
    }

    /**
     * Start a new QR code session for the company
     */
    public function start(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        $integration = CompanyWhatsAppIntegration::query()
            ->where("company_id", $companyId)
            ->first();

        if (!$integration) {
            $integration = new CompanyWhatsAppIntegration();
            $integration->company_id = $companyId;
            $integration->provider = CompanyWhatsAppIntegrationService::PROVIDER_META_CLOUD;
            $integration->integration_type = CompanyWhatsAppIntegrationService::INTEGRATION_TYPE_BAILEYS_QR;
            $integration->status = CompanyWhatsAppIntegrationService::STATUS_NOT_CONFIGURED;
            $integration->session_status = CompanyWhatsAppIntegrationService::SESSION_STATUS_DISCONNECTED;
            $integration->save();
        } else {
            $integration->integration_type = CompanyWhatsAppIntegrationService::INTEGRATION_TYPE_BAILEYS_QR;
            $integration->session_status = CompanyWhatsAppIntegrationService::SESSION_STATUS_DISCONNECTED;
            $integration->qr_code_base64 = null;
            $integration->baileys_phone = null;
            $integration->status = CompanyWhatsAppIntegrationService::STATUS_NOT_CONFIGURED;
            $integration->connected_at = null;
            $integration->save();
        }

        // Call the Baileys service to start a session
        $serviceUrl = rtrim((string) config("whatsapp.baileys_service_url", "http://whatsapp-qr-service:3001"), "/");
        $response = Http::timeout(10)
            ->acceptJson()
            ->asJson()
            ->post($serviceUrl . "/api/sessions", [
                "company_id" => $companyId,
            ]);

        if (!$response->successful()) {
            $integration->session_status = CompanyWhatsAppIntegrationService::SESSION_STATUS_ERROR;
            $integration->last_error = "Falha ao iniciar sessão no serviço QR.";
            $integration->save();

            return response()->json([
                "message" => "Falha ao iniciar sessão QR.",
                "data" => $this->integrationService->getForCompany($companyId),
            ], 502);
        }

        $raw = $response->json();
        $sessionData = is_array($raw) ? ($raw["data"] ?? []) : [];

        $integration->session_status = $sessionData["status"] ?? CompanyWhatsAppIntegrationService::SESSION_STATUS_CONNECTING;
        $integration->qr_code_base64 = $sessionData["qr_code"] ?? null;
        $integration->save();

        return response()->json([
            "message" => "Sessão QR iniciada. Escaneie o código com o WhatsApp.",
            "data" => $this->integrationService->getForCompany($companyId),
        ]);
    }

    /**
     * Get current QR code and session status
     */
    public function status(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        // Fetch latest status from Baileys service
        $serviceUrl = rtrim((string) config("whatsapp.baileys_service_url", "http://whatsapp-qr-service:3001"), "/");
        $response = Http::timeout(5)
            ->acceptJson()
            ->get($serviceUrl . "/api/sessions/{$companyId}/status");

        $remoteStatus = null;
        $remoteQrCode = null;

        if ($response->successful()) {
            $raw = $response->json();
            $sessionData = is_array($raw) ? ($raw["data"] ?? []) : [];
            $remoteStatus = $sessionData["status"] ?? null;
            $remoteQrCode = $sessionData["qr_code"] ?? null;
        }

        // Update local DB with remote status
        $integration = CompanyWhatsAppIntegration::query()
            ->where("company_id", $companyId)
            ->first();

        if ($integration && $remoteStatus) {
            $integration->session_status = $remoteStatus;
            if ($remoteQrCode) {
                $integration->qr_code_base64 = $remoteQrCode;
            }
            if ($remoteStatus === CompanyWhatsAppIntegrationService::SESSION_STATUS_CONNECTED) {
                $integration->status = CompanyWhatsAppIntegrationService::STATUS_CONFIGURED;
                if (!$integration->connected_at) {
                    $integration->connected_at = now();
                }
                $integration->last_error = null;
            }
            $integration->save();
        }

        return response()->json([
            "data" => $this->integrationService->getForCompany($companyId),
        ]);
    }

    /**
     * Disconnect / logout the QR session
     */
    public function logout(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        // Call Baileys service to logout
        $serviceUrl = rtrim((string) config("whatsapp.baileys_service_url", "http://whatsapp-qr-service:3001"), "/");
        Http::timeout(5)
            ->acceptJson()
            ->post($serviceUrl . "/api/sessions/{$companyId}/logout");

        // Update local DB
        $integration = CompanyWhatsAppIntegration::query()
            ->where("company_id", $companyId)
            ->first();

        if ($integration) {
            $integration->integration_type = CompanyWhatsAppIntegrationService::INTEGRATION_TYPE_META_CLOUD;
            $integration->session_status = CompanyWhatsAppIntegrationService::SESSION_STATUS_DISCONNECTED;
            $integration->qr_code_base64 = null;
            $integration->baileys_phone = null;
            $integration->status = CompanyWhatsAppIntegrationService::STATUS_NOT_CONFIGURED;
            $integration->connected_at = null;
            $integration->save();
        }

        return response()->json([
            "message" => "Sessão WhatsApp desconectada.",
            "data" => $this->integrationService->getForCompany($companyId),
        ]);
    }
}