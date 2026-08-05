<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Domain\AiKanbanMovementService;
use App\Services\Domain\ConversationResolverService;
use App\Services\Domain\FirstResponseCalculatorService;
use App\Services\Domain\KanbanInitialPlacementService;
use App\Services\Domain\LeadClassifierService;
use App\Services\Domain\LeadSourceService;
use App\Services\Domain\MarketingIntelligenceService;
use App\Services\Domain\RescueDetectorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WhatsappIngestionService
{
    public function __construct(
        private readonly LeadClassifierService $leadClassifier,
        private readonly ConversationResolverService $conversationResolver,
        private readonly FirstResponseCalculatorService $firstResponseCalculator,
        private readonly RescueDetectorService $rescueDetector,
        private readonly LeadSourceService $leadSourceService,
        private readonly KanbanInitialPlacementService $kanbanInitialPlacementService,
        private readonly AiKanbanMovementService $aiKanbanMovementService,
        private readonly MarketingIntelligenceService $marketingIntelligenceService,
    ) {
    }

    public function ingest(Company $company, array $payload): array
    {
        $direction = $payload["direction"];
        $phone = $this->normalizePhone($payload["phone"]);
        $provider = strtolower(trim($payload["provider"] ?? "whatsapp")) ?: "whatsapp";
        $source = strtolower(trim($payload["source"] ?? "desconhecido")) ?: "desconhecido";
        $externalMessageId = isset($payload["external_message_id"]) && trim((string) $payload["external_message_id"]) !== ""
            ? trim((string) $payload["external_message_id"])
            : null;
        $sentAt = isset($payload["sent_at"]) ? Carbon::parse($payload["sent_at"]) : now();

        if ($externalMessageId) {
            $existingMessage = Message::query()
                ->where("company_id", $company->id)
                ->where("provider", $provider)
                ->where("external_message_id", $externalMessageId)
                ->first();

            if ($existingMessage) {
                return [
                    "lead_id" => $existingMessage->lead_id,
                    "conversation_id" => $existingMessage->conversation_id,
                    "classification" => $existingMessage->metadata["classification"] ?? "lead_existente",
                    "is_rescue" => (bool) $existingMessage->is_rescue,
                    "duplicated" => true,
                ];
            }
        }

        $existingLead = Lead::where("company_id", $company->id)
            ->where("phone_e164", $phone)
            ->first();

        $classification = $this->leadClassifier->classify($existingLead, $direction, $sentAt, $company->id);
        $lead = $existingLead;

        if (!$existingLead) {
            $lead = Lead::create([
                "company_id" => $company->id,
                "owner_user_id" => $payload["owner_user_id"] ?? null,
                "name" => $payload["lead_name"] ?? null,
                "phone_e164" => $phone,
                "source" => $source,
                "source_method" => "auto",
                "source_updated_at" => now(),
                "is_repeat_lead" => false,
                "first_inbound_at" => $direction === "inbound" ? $sentAt : null,
                "last_inbound_at" => $direction === "inbound" ? $sentAt : null,
                "last_outbound_at" => $direction === "outbound" ? $sentAt : null,
                "metadata" => [],
            ]);

            $this->leadSourceService->applyInitialSource($lead, $source);
            $this->kanbanInitialPlacementService->placeLeadInInitialColumnIfMissing($company->id, $lead->id);
        } elseif ($classification === "lead_repetido") {
            $lead->is_repeat_lead = true;
            $this->leadSourceService->applyAutoSourceFromReentry($lead, $source);
        }

        $conversation = $this->conversationResolver->resolve(
            $company,
            $lead,
            $payload["owner_user_id"] ?? null,
            $sentAt
        );

        $isRescue = false;

        if ($direction === "inbound") {
            if (!$lead->first_inbound_at) {
                $lead->first_inbound_at = $sentAt;
            }
            $lead->last_inbound_at = $sentAt;
        }

        if ($direction === "outbound") {
            $isRescue = $this->rescueDetector->isRescue($lead, $sentAt);
            $this->firstResponseCalculator->applyIfNeeded($lead, $sentAt, $company);
            $lead->last_outbound_at = $sentAt;
        }

        Message::create([
            "company_id" => $company->id,
            "lead_id" => $lead->id,
            "conversation_id" => $conversation->id,
            "provider" => $provider,
            "direction" => $direction,
            "channel" => $payload["channel"] ?? "text",
            "body" => $payload["body"] ?? null,
            "audio_transcript" => $payload["audio_transcript"] ?? null,
            "sent_at" => $sentAt,
            "external_message_id" => $externalMessageId,
            "raw_payload" => $payload["raw_payload"] ?? null,
            "is_rescue" => $isRescue,
            "metadata" => [
                "classification" => $classification,
                "raw_source" => $source,
            ],
        ]);

        $conversation->last_message_at = $sentAt;
        $conversation->save();

        $lead->save();

        $this->applyAiIntelligenceIfNeeded($lead, $direction);

        $this->aiKanbanMovementService->evaluateAndMove($company->id, $lead->id);

        return [
            "lead_id" => $lead->id,
            "conversation_id" => $conversation->id,
            "classification" => $classification,
            "is_rescue" => $isRescue,
            "duplicated" => false,
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace("/\\D+/", "", $phone);

        // Detect likely-unresolved LID identifiers (very long numeric strings, >15 digits).
        // These are not real phone numbers but WhatsApp Linked Identity fallbacks.
        // We still store them so the lead is captured, but log a warning for follow-up.
        if (strlen($digits) > 15) {
            Log::warning("Phone looks like an unresolved LID identifier: {$phone} (digits: {$digits})");
        }

        if (str_starts_with($digits, "55")) {
            return "+" . $digits;
        }

        return "+55" . $digits;
    }

    /**
     * Uses AI marketing intelligence on new inbound leads whose origin was not
     * tracked by the incoming payload, so the lead can be attributed to a
     * Facebook/Instagram/Google/TikTok campaign automatically.
     */
    private function applyAiIntelligenceIfNeeded(Lead $lead, string $direction): void
    {
        if ($direction !== "inbound") {
            return;
        }

        // Only attempt automatic classification when the source is unknown.
        if ($lead->source !== "desconhecido" && $lead->source !== "meta_cloud" && $lead->source !== "whatsapp_qr") {
            // Still try to detect a tracked creative link.
            $this->tryDetectCreative($lead);
            return;
        }

        $result = $this->marketingIntelligenceService->classifyLeadSourceByAi($lead);
        $lead->refresh();

        $this->tryDetectCreative($lead);
    }

    private function tryDetectCreative(Lead $lead): void
    {
        if ($lead->creative_url) {
            return;
        }

        $firstMessage = \App\Models\Message::query()
            ->where("company_id", $lead->company_id)
            ->where("lead_id", $lead->id)
            ->where("direction", "inbound")
            ->orderBy("sent_at")
            ->orderBy("id")
            ->value("body");

        if (!$firstMessage || !preg_match('/https?:\/\/[^\s]+/i', (string) $firstMessage)) {
            return;
        }

        try {
            $this->marketingIntelligenceService->analyzeLeadCreative($lead);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Falha ao analisar criativo via IA: " . $e->getMessage());
        }
    }
}