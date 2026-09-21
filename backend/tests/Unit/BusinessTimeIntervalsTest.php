<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\CompanySettingsService;
use App\Services\Domain\BusinessTimeCalculatorService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BusinessTimeIntervalsTest extends TestCase
{
    #[DataProvider('intervals')]
    public function test_daily_intersections(string $start, string $end, int $expected): void
    {
        $settings = $this->createStub(CompanySettingsService::class);
        $settings->method('timezone')->willReturn('America/Sao_Paulo');
        $settings->method('workingDays')->willReturn([1, 2, 3, 4, 5]);
        $settings->method('workdayStartTime')->willReturn('08:00:00');
        $settings->method('workdayEndTime')->willReturn('18:00:00');
        $settings->method('lunchStartTime')->willReturn('12:00:00');
        $settings->method('lunchEndTime')->willReturn('13:00:00');
        $company = new Company;
        $company->id = 1;
        $from = Carbon::parse($start, 'America/Sao_Paulo');
        $to = Carbon::parse($end, 'America/Sao_Paulo');
        $originalFrom = $from->copy();
        $originalTo = $to->copy();

        $this->assertSame($expected, (new BusinessTimeCalculatorService($settings))
            ->calculateBusinessSeconds($from, $to, $company));
        $this->assertTrue($from->eq($originalFrom));
        $this->assertTrue($to->eq($originalTo));
    }

    public static function intervals(): array
    {
        return [
            'opening seconds' => ['2026-05-06 07:59:30', '2026-05-06 08:00:30', 30],
            'closing seconds' => ['2026-05-06 17:59:30', '2026-05-06 18:00:30', 30],
            'lunch seconds' => ['2026-05-06 11:59:30', '2026-05-06 13:00:30', 60],
            'only lunch' => ['2026-05-06 12:10:00', '2026-05-06 12:50:00', 0],
            'weekend' => ['2026-05-08 17:30:00', '2026-05-11 08:30:00', 3600],
            'utc input' => ['2026-05-06 11:00:00Z', '2026-05-06 12:00:00Z', 3600],
            'empty' => ['2026-05-06 08:00:00', '2026-05-06 08:00:00', 0],
            'reversed' => ['2026-05-07 08:00:00', '2026-05-06 08:00:00', 0],
            '47 days' => ['2026-08-01 00:00:00', '2026-09-17 00:00:00', 1069200],
            'dst weekend' => ['2018-11-02 17:30:00', '2018-11-05 08:30:00', 3600],
        ];
    }
}
