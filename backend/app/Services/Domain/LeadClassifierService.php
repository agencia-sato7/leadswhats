<?php

namespace App\Services\Domain;

use App\Models\Lead;
use Carbon\Carbon;

class LeadClassifierService
{
    public function classify(?Lead $lead, string $direction, Carbon $sentAt, int $repeatWindowDays = 90): string
    {
        if (!$lead) {
            return 'lead_novo';
        }

        if ($direction === 'inbound' && $this->isRepeatedLead($lead, $sentAt, $repeatWindowDays)) {
            return 'lead_repetido';
        }

        return 'lead_existente';
    }

    public function isRepeatedLead(Lead $lead, Carbon $sentAt, int $repeatWindowDays = 90): bool
    {
        $lastInbound = $lead->last_inbound_at;

        if (!$lastInbound) {
            return false;
        }

        return $lastInbound->diffInDays($sentAt, true) >= $repeatWindowDays;
    }
}
