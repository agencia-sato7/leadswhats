<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Services\CompanySettingsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_configured_values_when_company_has_setting(): void
    {
        $company = Company::create([
            'name' => 'Empresa A',
            'slug' => 'empresa-a',
            'timezone' => 'America/Sao_Paulo',
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Manaus',
            'workday_start_time' => '09:00:00',
            'workday_end_time' => '17:30:00',
            'lunch_start_time' => '12:15:00',
            'lunch_end_time' => '13:00:00',
            'working_days' => [1, 2, 3],
            'repeated_lead_window_days' => 45,
            'rescue_threshold_hours' => 36,
            'first_response_sla_minutes' => 20,
            'follow_up_sla_hours' => 18,
            'stale_conversation_hours' => 72,
            'webhook_token' => null,
        ]);

        $service = app(CompanySettingsService::class);
        $data = $service->getForCompany($company->id);

        $this->assertSame('America/Manaus', $data['timezone']);
        $this->assertSame('09:00:00', $data['workday_start_time']);
        $this->assertSame('17:30:00', $data['workday_end_time']);
        $this->assertSame('12:15:00', $data['lunch_start_time']);
        $this->assertSame('13:00:00', $data['lunch_end_time']);
        $this->assertSame([1, 2, 3], $service->workingDays($company->id));
        $this->assertSame(45, $service->repeatedLeadWindowDays($company->id));
        $this->assertSame(36, $service->rescueThresholdHours($company->id));
        $this->assertSame(20, $service->firstResponseSlaMinutes($company->id));
        $this->assertSame(18, $service->followUpSlaHours($company->id));
        $this->assertSame(72, $service->staleConversationHours($company->id));
    }

    public function test_returns_fallbacks_when_company_has_no_setting(): void
    {
        $company = Company::create([
            'name' => 'Empresa B',
            'slug' => 'empresa-b',
            'timezone' => 'America/Sao_Paulo',
            'work_start' => '08:00',
            'work_end' => '18:00',
            'lunch_start' => '12:00',
            'lunch_end' => '13:00',
        ]);

        $service = app(CompanySettingsService::class);

        $this->assertSame('America/Sao_Paulo', $service->timezone($company->id));
        $this->assertSame('08:00:00', $service->workdayStartTime($company->id));
        $this->assertSame('18:00:00', $service->workdayEndTime($company->id));
        $this->assertSame('12:00:00', $service->lunchStartTime($company->id));
        $this->assertSame('13:00:00', $service->lunchEndTime($company->id));
        $this->assertSame([1, 2, 3, 4, 5], $service->workingDays($company->id));
        $this->assertSame(15, $service->firstResponseSlaMinutes($company->id));
        $this->assertSame(24, $service->followUpSlaHours($company->id));
        $this->assertSame(48, $service->staleConversationHours($company->id));
    }

    public function test_normalizes_legacy_hour_format(): void
    {
        $company = Company::create([
            'name' => 'Empresa C',
            'slug' => 'empresa-c',
            'work_start' => '09:30',
            'work_end' => '19:00',
            'lunch_start' => '13:00',
            'lunch_end' => '14:00',
        ]);

        $service = app(CompanySettingsService::class);

        $this->assertSame('09:30:00', $service->workdayStartTime($company->id));
        $this->assertSame('19:00:00', $service->workdayEndTime($company->id));
        $this->assertSame('13:00:00', $service->lunchStartTime($company->id));
        $this->assertSame('14:00:00', $service->lunchEndTime($company->id));
    }

    public function test_returns_defaults_for_nonexistent_company(): void
    {
        $service = app(CompanySettingsService::class);

        $this->assertSame('America/Sao_Paulo', $service->timezone(999999));
        $this->assertSame([1, 2, 3, 4, 5], $service->workingDays(999999));
        $this->assertSame(24, $service->rescueThresholdHours(999999));
        $this->assertSame(90, $service->repeatedLeadWindowDays(999999));
        $this->assertSame(15, $service->firstResponseSlaMinutes(999999));
        $this->assertSame(24, $service->followUpSlaHours(999999));
        $this->assertSame(48, $service->staleConversationHours(999999));
    }

    public function test_demo_seed_contains_operational_sla_defaults(): void
    {
        $this->seed(DatabaseSeeder::class);

        $company = Company::query()->where('slug', 'empresa-demo')->first();
        $this->assertNotNull($company);

        $settings = CompanyBusinessSetting::query()
            ->where('company_id', $company->id)
            ->first();

        $this->assertNotNull($settings);
        $this->assertSame(15, (int) $settings->first_response_sla_minutes);
        $this->assertSame(24, (int) $settings->follow_up_sla_hours);
        $this->assertSame(48, (int) $settings->stale_conversation_hours);
    }
}
