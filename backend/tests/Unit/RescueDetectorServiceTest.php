<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Lead;
use App\Services\Domain\RescueDetectorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RescueDetectorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_company_rescue_threshold_hours(): void
    {
        $company = Company::create([
            "name" => "Empresa D",
            "slug" => "empresa-d",
        ]);

        CompanyBusinessSetting::create([
            "company_id" => $company->id,
            "rescue_threshold_hours" => 48,
        ]);

        $lead = Lead::create([
            "company_id" => $company->id,
            "name" => "Lead D",
            "phone_e164" => "+5511999990009",
            "source" => "desconhecido",
            "last_inbound_at" => Carbon::parse("2026-05-01 10:00:00"),
            "last_outbound_at" => Carbon::parse("2026-05-02 10:00:00"),
        ]);

        $service = app(RescueDetectorService::class);

        $this->assertFalse($service->isRescue($lead, Carbon::parse("2026-05-04 09:00:00")));
        $this->assertTrue($service->isRescue($lead, Carbon::parse("2026-05-04 10:00:00")));
    }
}
