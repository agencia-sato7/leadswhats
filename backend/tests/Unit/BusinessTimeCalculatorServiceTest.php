<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Services\Domain\BusinessTimeCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTimeCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_respects_configured_workday_window(): void
    {
        $company = Company::create([
            "name" => "Empresa E",
            "slug" => "empresa-e",
        ]);

        CompanyBusinessSetting::create([
            "company_id" => $company->id,
            "timezone" => "America/Sao_Paulo",
            "workday_start_time" => "09:00:00",
            "workday_end_time" => "17:00:00",
            "lunch_start_time" => null,
            "lunch_end_time" => null,
            "working_days" => [1, 2, 3, 4, 5],
        ]);

        $service = app(BusinessTimeCalculatorService::class);

        $start = Carbon::parse("2026-05-06 08:00:00-03:00");
        $end = Carbon::parse("2026-05-06 10:00:00-03:00");

        $this->assertSame(3600, $service->calculateBusinessSeconds($start, $end, $company));
    }

    public function test_ignores_configured_lunch_break(): void
    {
        $company = Company::create([
            "name" => "Empresa F",
            "slug" => "empresa-f",
        ]);

        CompanyBusinessSetting::create([
            "company_id" => $company->id,
            "timezone" => "America/Sao_Paulo",
            "workday_start_time" => "08:00:00",
            "workday_end_time" => "18:00:00",
            "lunch_start_time" => "12:00:00",
            "lunch_end_time" => "13:00:00",
            "working_days" => [1, 2, 3, 4, 5],
        ]);

        $service = app(BusinessTimeCalculatorService::class);

        $start = Carbon::parse("2026-05-06 11:30:00-03:00");
        $end = Carbon::parse("2026-05-06 13:30:00-03:00");

        $this->assertSame(3600, $service->calculateBusinessSeconds($start, $end, $company));
    }

    public function test_ignores_days_outside_working_days(): void
    {
        $company = Company::create([
            "name" => "Empresa G",
            "slug" => "empresa-g",
        ]);

        CompanyBusinessSetting::create([
            "company_id" => $company->id,
            "timezone" => "America/Sao_Paulo",
            "workday_start_time" => "08:00:00",
            "workday_end_time" => "18:00:00",
            "working_days" => [1, 2, 3, 4, 5],
        ]);

        $service = app(BusinessTimeCalculatorService::class);

        $start = Carbon::parse("2026-05-09 10:00:00-03:00");
        $end = Carbon::parse("2026-05-09 11:00:00-03:00");

        $this->assertSame(0, $service->calculateBusinessSeconds($start, $end, $company));
    }

    public function test_preserves_fallback_when_company_has_no_setting(): void
    {
        $company = Company::create([
            "name" => "Empresa H",
            "slug" => "empresa-h",
            "timezone" => "America/Sao_Paulo",
            "work_start" => "08:00",
            "work_end" => "18:00",
            "lunch_start" => "12:00",
            "lunch_end" => "13:00",
        ]);

        $service = app(BusinessTimeCalculatorService::class);

        $start = Carbon::parse("2026-05-06 11:30:00-03:00");
        $end = Carbon::parse("2026-05-06 13:30:00-03:00");

        $this->assertSame(3600, $service->calculateBusinessSeconds($start, $end, $company));
    }
}
