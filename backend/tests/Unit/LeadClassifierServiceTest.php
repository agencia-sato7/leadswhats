<?php

namespace Tests\Unit;

use App\Models\Lead;
use App\Services\Domain\LeadClassifierService;
use Carbon\Carbon;
use Tests\TestCase;

class LeadClassifierServiceTest extends TestCase
{
    public function test_classifies_new_when_lead_does_not_exist(): void
    {
        $service = new LeadClassifierService();

        $classification = $service->classify(null, "inbound", Carbon::parse("2026-05-06 10:00:00"));

        $this->assertSame("lead_novo", $classification);
    }

    public function test_classifies_repeated_when_last_inbound_is_older_than_90_days(): void
    {
        $service = new LeadClassifierService();
        $lead = new Lead();
        $lead->last_inbound_at = Carbon::parse("2026-01-01 10:00:00");

        $classification = $service->classify($lead, "inbound", Carbon::parse("2026-05-06 10:00:00"));

        $this->assertSame("lead_repetido", $classification);
    }

    public function test_classifies_existing_when_not_repeated_or_not_inbound(): void
    {
        $service = new LeadClassifierService();
        $lead = new Lead();
        $lead->last_inbound_at = Carbon::parse("2026-05-01 10:00:00");

        $this->assertSame(
            "lead_existente",
            $service->classify($lead, "inbound", Carbon::parse("2026-05-06 10:00:00"))
        );

        $this->assertSame(
            "lead_existente",
            $service->classify($lead, "outbound", Carbon::parse("2026-09-01 10:00:00"))
        );
    }
}
