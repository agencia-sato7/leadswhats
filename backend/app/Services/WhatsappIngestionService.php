<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Domain\ConversationResolverService;
use App\Services\Domain\FirstResponseCalculatorService;
use App\Services\Domain\LeadClassifierService;
use App\Services\Domain\LeadSourceService;
use App\Services\Domain\RescueDetectorService;
use Carbon\Carbon;

class WhatsappIngestionService
{
    public function __construct(
        private readonly LeadClassifierService $leadClassifier,
        private readonly ConversationResolverService $conversationResolver,
        private readonly FirstResponseCalculatorService $firstResponseCalculator,
        private readonly RescueDetectorService $rescueDetector,
        private readonly LeadSourceService $leadSourceService,
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

        $classification = $this->leadClassifier->classify($existingLead, $direction, $sentAt);
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

        if (str_starts_with($digits, "55")) {
            return "+" . $digits;
        }

        return "+55" . $digits;
    }
}
