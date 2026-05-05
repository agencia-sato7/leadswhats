<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSourceHistory;
use App\Models\Message;
use Carbon\Carbon;

class WhatsappIngestionService
{
    public function ingest(Company $company, array $payload): array
    {
        $direction = $payload['direction'];
        $phone = $this->normalizePhone($payload['phone']);
        $source = strtolower(trim($payload['source'] ?? 'desconhecido')) ?: 'desconhecido';
        $sentAt = isset($payload['sent_at']) ? Carbon::parse($payload['sent_at']) : now();

        $lead = Lead::where('company_id', $company->id)
            ->where('phone_e164', $phone)
            ->first();

        $classification = 'lead_existente';

        if (!$lead) {
            $lead = Lead::create([
                'company_id' => $company->id,
                'owner_user_id' => $payload['owner_user_id'] ?? null,
                'name' => $payload['lead_name'] ?? null,
                'phone_e164' => $phone,
                'source' => $source,
                'source_method' => 'auto',
                'source_updated_at' => now(),
                'is_repeat_lead' => false,
                'first_inbound_at' => $direction === 'inbound' ? $sentAt : null,
                'last_inbound_at' => $direction === 'inbound' ? $sentAt : null,
                'last_outbound_at' => $direction === 'outbound' ? $sentAt : null,
                'metadata' => [],
            ]);

            LeadSourceHistory::create([
                'company_id' => $company->id,
                'lead_id' => $lead->id,
                'previous_source' => null,
                'new_source' => $source,
                'change_type' => 'auto',
                'reason' => 'Classificação inicial via webhook.',
                'changed_at' => now(),
            ]);

            $classification = 'lead_novo';
        } elseif ($direction === 'inbound' && $this->isRepeatedLead($lead, $sentAt)) {
            $lead->is_repeat_lead = true;
            if ($source !== 'desconhecido') {
                $this->changeLeadSource($lead, $source, 'auto', 'Reentrada detectada com origem rastreada.');
            }
            $classification = 'lead_repetido';
        }

        $conversation = Conversation::where('company_id', $company->id)
            ->where('lead_id', $lead->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'company_id' => $company->id,
                'lead_id' => $lead->id,
                'owner_user_id' => $payload['owner_user_id'] ?? $lead->owner_user_id,
                'status' => 'active',
                'started_at' => $sentAt,
                'last_message_at' => $sentAt,
            ]);
        }

        $isRescue = false;

        if ($direction === 'inbound') {
            if (!$lead->first_inbound_at) {
                $lead->first_inbound_at = $sentAt;
            }
            $lead->last_inbound_at = $sentAt;
        }

        if ($direction === 'outbound') {
            if ($lead->last_outbound_at && $lead->last_inbound_at && $lead->last_inbound_at->lt($lead->last_outbound_at) && $lead->last_outbound_at->diffInHours($sentAt, true) >= 24) {
                $isRescue = true;
            }

            if ($lead->first_response_seconds === null && $lead->first_inbound_at) {
                $lead->first_response_seconds = $this->businessSecondsBetween($lead->first_inbound_at, $sentAt, $company);
            }

            $lead->last_outbound_at = $sentAt;
        }

        Message::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'channel' => $payload['channel'] ?? 'text',
            'body' => $payload['body'] ?? null,
            'audio_transcript' => $payload['audio_transcript'] ?? null,
            'sent_at' => $sentAt,
            'external_message_id' => $payload['external_message_id'] ?? null,
            'is_rescue' => $isRescue,
            'metadata' => [
                'classification' => $classification,
                'raw_source' => $source,
            ],
        ]);

        $conversation->last_message_at = $sentAt;
        $conversation->save();

        $lead->save();

        return [
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'classification' => $classification,
            'is_rescue' => $isRescue,
        ];
    }

    private function changeLeadSource(Lead $lead, string $newSource, string $changeType, string $reason): void
    {
        if ($lead->source === $newSource) {
            return;
        }

        LeadSourceHistory::create([
            'company_id' => $lead->company_id,
            'lead_id' => $lead->id,
            'previous_source' => $lead->source,
            'new_source' => $newSource,
            'change_type' => $changeType,
            'reason' => $reason,
            'changed_at' => now(),
        ]);

        $lead->source = $newSource;
        $lead->source_method = $changeType === 'manual' ? 'manual' : 'auto';
        $lead->source_updated_at = now();
    }

    private function isRepeatedLead(Lead $lead, Carbon $sentAt): bool
    {
        $lastInbound = $lead->last_inbound_at;
        if (!$lastInbound) {
            return false;
        }

        return $lastInbound->diffInDays($sentAt, true) >= 90;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '55')) {
            return '+' . $digits;
        }

        return '+55' . $digits;
    }

    private function businessSecondsBetween(Carbon $start, Carbon $end, Company $company): int
    {
        if ($end->lte($start)) {
            return 0;
        }

        $cursor = $start->copy();
        $total = 0;

        while ($cursor->lt($end)) {
            $next = $cursor->copy()->addMinute();

            if ($this->isBusinessMinute($cursor, $company)) {
                $remaining = $cursor->diffInSeconds($end, true);
                $total += (int) min(60, $remaining);
            }

            $cursor = $next;
        }

        return max(0, $total);
    }

    private function isBusinessMinute(Carbon $moment, Company $company): bool
    {
        if ($moment->isWeekend()) {
            return false;
        }

        $workStart = Carbon::parse($moment->toDateString() . ' ' . $company->work_start);
        $workEnd = Carbon::parse($moment->toDateString() . ' ' . $company->work_end);

        if ($moment->lt($workStart) || $moment->gte($workEnd)) {
            return false;
        }

        if ($company->lunch_start && $company->lunch_end) {
            $lunchStart = Carbon::parse($moment->toDateString() . ' ' . $company->lunch_start);
            $lunchEnd = Carbon::parse($moment->toDateString() . ' ' . $company->lunch_end);
            if ($moment->gte($lunchStart) && $moment->lt($lunchEnd)) {
                return false;
            }
        }

        return true;
    }
}
