<?php

namespace App\Services\Domain;

use App\Models\Lead;
use App\Services\CompanySettingsService;
use Carbon\Carbon;

class RescueDetectorService
{
    public function __construct(private readonly CompanySettingsService $settings)
    {
    }

    public function isRescue(Lead $lead, Carbon $sentAt): bool
    {
        if (!$lead->last_outbound_at || !$lead->last_inbound_at) {
            return false;
        }

        if (!$lead->last_inbound_at->lt($lead->last_outbound_at)) {
            return false;
        }

        $threshold = $lead->company_id
            ? $this->settings->rescueThresholdHours($lead->company_id)
            : 24;

        return $lead->last_outbound_at->diffInHours($sentAt, true) >= $threshold;
    }
}
