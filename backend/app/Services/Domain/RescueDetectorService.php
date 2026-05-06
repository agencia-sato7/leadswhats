<?php

namespace App\Services\Domain;

use App\Models\Lead;
use Carbon\Carbon;

class RescueDetectorService
{
    public function isRescue(Lead $lead, Carbon $sentAt, int $vacuumThresholdHours = 24): bool
    {
        if (!$lead->last_outbound_at || !$lead->last_inbound_at) {
            return false;
        }

        if (!$lead->last_inbound_at->lt($lead->last_outbound_at)) {
            return false;
        }

        return $lead->last_outbound_at->diffInHours($sentAt, true) >= $vacuumThresholdHours;
    }
}
