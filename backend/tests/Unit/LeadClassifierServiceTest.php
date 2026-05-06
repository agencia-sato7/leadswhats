<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Lead;
use App\Services\Domain\LeadClassifierService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadClassifierServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_new_when_lead_does_not_exist(): void
    {
        $service = app(LeadClassifierService::class);

        $classification = $service->classify(null, 'inbound', Carbon::parse('2026-05-06 10:00:00'));

        $this->assertSame('lead_novo', $classification);
    }

    public function test_uses_configured_repeated_lead_window_days(): void
    {
        $company = Company::create([
            'name' => 'Empresa I',
            'slug' => 'empresa-i',
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'repeated_lead_window_days' => 30,
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead I',
            'phone_e164' => '+5511999990010',
            'source' => 'desconhecido',
            'last_inbound_at' => Carbon::parse('2026-04-01 10:00:00'),
        ]);

        $service = app(LeadClassifierService::class);

        $this->assertSame(
            'lead_repetido',
            $service->classify($lead, 'inbound', Carbon::parse('2026-05-02 10:00:00'), $company->id)
        );

        $this->assertSame(
            'lead_existente',
            $service->classify($lead, 'inbound', Carbon::parse('2026-04-20 10:00:00'), $company->id)
        );
    }

    public function test_uses_default_window_when_company_has_no_setting(): void
    {
        $company = Company::create([
            'name' => 'Empresa Sem Setting',
            'slug' => 'empresa-sem-setting',
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead Default',
            'phone_e164' => '+5511999990012',
            'source' => 'desconhecido',
            'last_inbound_at' => Carbon::parse('2026-02-01 10:00:00'),
        ]);

        $service = app(LeadClassifierService::class);

        $this->assertSame(
            'lead_existente',
            $service->classify($lead, 'inbound', Carbon::parse('2026-04-30 10:00:00'), $company->id)
        );

        $this->assertSame(
            'lead_repetido',
            $service->classify($lead, 'inbound', Carbon::parse('2026-05-05 10:00:00'), $company->id)
        );
    }

    public function test_classifies_existing_when_not_repeated_or_not_inbound(): void
    {
        $company = Company::create([
            'name' => 'Empresa J',
            'slug' => 'empresa-j',
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead J',
            'phone_e164' => '+5511999990011',
            'source' => 'desconhecido',
            'last_inbound_at' => Carbon::parse('2026-05-01 10:00:00'),
        ]);

        $service = app(LeadClassifierService::class);

        $this->assertSame(
            'lead_existente',
            $service->classify($lead, 'inbound', Carbon::parse('2026-05-06 10:00:00'), $company->id)
        );

        $this->assertSame(
            'lead_existente',
            $service->classify($lead, 'outbound', Carbon::parse('2026-09-01 10:00:00'), $company->id)
        );
    }
}
