<?php

namespace App\Services\Domain;

use App\Models\Lead;
use App\Services\CompanySettingsService;
use Carbon\Carbon;

class LeadClassifierService
{
    public function __construct(private readonly CompanySettingsService $settings)
    {
    }

    public function classify(?Lead $lead, string $direction, Carbon $sentAt, ?int $companyId = null): string
    {
        if (!$lead) {
            return "lead_novo";
        }

        $resolvedCompanyId = $companyId ?? $lead->company_id;
        $repeatWindowDays = $resolvedCompanyId
            ? $this->settings->repeatedLeadWindowDays($resolvedCompanyId)
            : 90;

        if ($direction === "inbound" && $this->isRepeatedLead($lead, $sentAt, $repeatWindowDays)) {
            return "lead_repetido";
        }

        return "lead_existente";
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
