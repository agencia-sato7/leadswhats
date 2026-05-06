<?php

namespace App\Services\Domain;

use App\Models\Company;
use App\Models\Lead;
use Carbon\Carbon;

class FirstResponseCalculatorService
{
    public function __construct(private readonly BusinessTimeCalculatorService $businessTimeCalculator)
    {
    }

    public function applyIfNeeded(Lead $lead, Carbon $sentAt, Company $company): void
    {
        if ($lead->first_response_seconds !== null || !$lead->first_inbound_at) {
            return;
        }

        $lead->first_response_seconds = $this->businessTimeCalculator
            ->calculateBusinessSeconds($lead->first_inbound_at, $sentAt, $company);
    }
}
