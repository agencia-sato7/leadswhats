<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\Domain\BusinessTimeCalculatorService;
use Carbon\Carbon;
use Tests\TestCase;

class BusinessTimeCalculatorServiceTest extends TestCase
{
    public function test_calculates_business_seconds_respecting_lunch_break(): void
    {
        $service = new BusinessTimeCalculatorService();

        $company = new Company([
            "work_start" => "08:00",
            "work_end" => "18:00",
            "lunch_start" => "12:00",
            "lunch_end" => "13:00",
        ]);

        $start = Carbon::parse("2026-05-06 11:30:00");
        $end = Carbon::parse("2026-05-06 13:30:00");

        $seconds = $service->calculateBusinessSeconds($start, $end, $company);

        $this->assertSame(3600, $seconds);
    }

    public function test_ignores_weekend_time(): void
    {
        $service = new BusinessTimeCalculatorService();

        $company = new Company([
            "work_start" => "08:00",
            "work_end" => "18:00",
            "lunch_start" => null,
            "lunch_end" => null,
        ]);

        $start = Carbon::parse("2026-05-09 10:00:00");
        $end = Carbon::parse("2026-05-09 11:00:00");

        $seconds = $service->calculateBusinessSeconds($start, $end, $company);

        $this->assertSame(0, $seconds);
    }
}
