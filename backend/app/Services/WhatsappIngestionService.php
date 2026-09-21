<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Domain\ConversationResolverService;
use App\Services\Domain\FirstResponseCalculatorService;
use App\Services\Domain\KanbanInitialPlacementService;
use App\Services\Domain\LeadClassifierService;
use App\Services\Domain\LeadSourceService;
use App\Services\Domain\PhoneNormalizationService;
use App\Services\Domain\RescueDetectorService;
use Carbon\Carbon;
use InvalidArgumentException;

class WhatsappIngestionService
{
    public function __construct(
        private readonly LeadClassifierService $leadClassifier,
        private readonly ConversationResolverService $conversationResolver,
        private readonly FirstResponseCalculatorService $firstResponseCalculator,
        private readonly RescueDetectorService $rescueDetector,
        private readonly LeadSourceService $leadSourceService,
        private readonly KanbanInitialPlacementService $kanbanInitialPlacementService,
        private readonly PhoneNormalizationService $phoneNormalizer,
    ) {
    }

    public function ingest(Company $company, array $payload): array
    {
        $direction = $payload["direction"];
        $phone = $this->phoneNormalizer->normalize($payload["phone"]);
        $provider = strtolower(trim($payload["provider"] ?? ""));
        $source = strtolower(trim($payload["source"] ?? "desconhecido")) ?: "desconhecido";

        if (!in_array($provider, ['fake', 'meta_cloud'], true)) {
            throw new InvalidArgumentException('Provider de ingestão inválido. Use fake ou meta_cloud.');
        }

        if (in_array($source, ['baileys_qr', 'baileys_qr_history', 'whatsapp_qr'], true)) {
            throw new InvalidArgumentException('A origem informada é apenas histórica e não aceita novas gravações.');
        }
        $externalMessageId = isset($payload["external_message_id"]) && trim((string) $payload["external_message_id"]) !== ""
            ? trim((string) $payload["external_message_id"])
            : null;
        $sentAt = isset($payload["sent_at"]) ? Carbon::parse($payload["sent_at"]) : now();

        $syncSource = isset($payload["sync_source"]) && trim((string) $payload["sync_source"]) !== ""
            ? trim((string) $payload["sync_source"])
            : null;

        // Importação de histórico do WhatsApp Business app (webhook `history`):
        // não dispara regras de tempo real (resgate/primeira resposta) e não
        // "regride" as datas já registradas do lead.
        $isHistoricalSync = (bool) ($payload["is_historical_sync"] ?? false);

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
        $profileName = trim((string) ($payload['lead_name'] ?? ''));

        if (!$existingLead) {
            $lead = Lead::create([
                "company_id" => $company->id,
                "owner_user_id" => $payload["owner_user_id"] ?? null,
                "name" => $profileName !== '' ? $profileName : null,
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
        } elseif ($lead && trim((string) $lead->name) === '' && $profileName !== '') {
            $lead->name = $profileName;
        }

        if ($existingLead && $classification === "lead_repetido") {
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
            if (!$lead->first_inbound_at || ($isHistoricalSync && $sentAt->lessThan($lead->first_inbound_at))) {
                $lead->first_inbound_at = $sentAt;
            }
            if (!$lead->last_inbound_at || $sentAt->greaterThan($lead->last_inbound_at)) {
                $lead->last_inbound_at = $sentAt;
            }
        }

        if ($direction === "outbound") {
            if (!$isHistoricalSync) {
                $isRescue = $this->rescueDetector->isRescue($lead, $sentAt);
                $this->firstResponseCalculator->applyIfNeeded($lead, $sentAt, $company);
            }
            if (!$lead->last_outbound_at || $sentAt->greaterThan($lead->last_outbound_at)) {
                $lead->last_outbound_at = $sentAt;
            }
        }

        $message = Message::create([
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
                "sync_source" => $syncSource,
                "is_historical_sync" => $isHistoricalSync,
            ],
        ]);

        if (!$conversation->last_message_at || $sentAt->greaterThan($conversation->last_message_at)) {
            $conversation->last_message_at = $sentAt;
        }
        $conversation->save();

        $lead->save();

        return [
            "message_id" => $message->id,
            "lead_id" => $lead->id,
            "conversation_id" => $conversation->id,
            "classification" => $classification,
            "is_rescue" => $isRescue,
            "duplicated" => false,
        ];
    }

}
