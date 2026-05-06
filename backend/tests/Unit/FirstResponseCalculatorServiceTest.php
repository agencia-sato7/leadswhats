<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Lead;
use App\Services\Domain\BusinessTimeCalculatorService;
use App\Services\Domain\FirstResponseCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstResponseCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculates_first_response_inside_workday(): void
    {
        $company = Company::create(['name' => 'Empresa 1', 'slug' => 'empresa-1']);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'workday_start_time' => '08:00:00',
            'workday_end_time' => '18:00:00',
            'lunch_start_time' => '12:00:00',
            'lunch_end_time' => '13:00:00',
            'working_days' => [1, 2, 3, 4, 5],
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead 1',
            'phone_e164' => '+5511999991111',
            'source' => 'desconhecido',
            'first_inbound_at' => Carbon::parse('2026-05-06T10:00:00-03:00'),
        ]);

        $sentAt = Carbon::parse('2026-05-06T10:30:00-03:00');

        $service = app(FirstResponseCalculatorService::class);
        $service->applyIfNeeded($lead, $sentAt, $company);

        $expected = app(BusinessTimeCalculatorService::class)
            ->calculateBusinessSeconds($lead->first_inbound_at, $sentAt, $company);

        $this->assertSame($expected, $lead->first_response_seconds);
    }

    public function test_calculates_first_response_across_off_hours_lunch_and_weekend(): void
    {
        $company = Company::create(['name' => 'Empresa 2', 'slug' => 'empresa-2']);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'workday_start_time' => '08:00:00',
            'workday_end_time' => '18:00:00',
            'lunch_start_time' => '12:00:00',
            'lunch_end_time' => '13:00:00',
            'working_days' => [1, 2, 3, 4, 5],
        ]);

        $service = app(FirstResponseCalculatorService::class);
        $calculator = app(BusinessTimeCalculatorService::class);

        $leadOffHours = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead Off Hours',
            'phone_e164' => '+5511999991112',
            'source' => 'desconhecido',
            'first_inbound_at' => Carbon::parse('2026-05-06T20:00:00-03:00'),
        ]);
        $offHoursSentAt = Carbon::parse('2026-05-07T08:30:00-03:00');
        $service->applyIfNeeded($leadOffHours, $offHoursSentAt, $company);
        $this->assertSame(
            $calculator->calculateBusinessSeconds($leadOffHours->first_inbound_at, $offHoursSentAt, $company),
            $leadOffHours->first_response_seconds
        );

        $leadLunch = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead Lunch',
            'phone_e164' => '+5511999991113',
            'source' => 'desconhecido',
            'first_inbound_at' => Carbon::parse('2026-05-06T11:50:00-03:00'),
        ]);
        $lunchSentAt = Carbon::parse('2026-05-06T13:10:00-03:00');
        $service->applyIfNeeded($leadLunch, $lunchSentAt, $company);
        $this->assertSame(
            $calculator->calculateBusinessSeconds($leadLunch->first_inbound_at, $lunchSentAt, $company),
            $leadLunch->first_response_seconds
        );

        $leadWeekend = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead Weekend',
            'phone_e164' => '+5511999991114',
            'source' => 'desconhecido',
            'first_inbound_at' => Carbon::parse('2026-05-08T17:50:00-03:00'),
        ]);
        $weekendSentAt = Carbon::parse('2026-05-11T08:10:00-03:00');
        $service->applyIfNeeded($leadWeekend, $weekendSentAt, $company);
        $this->assertSame(
            $calculator->calculateBusinessSeconds($leadWeekend->first_inbound_at, $weekendSentAt, $company),
            $leadWeekend->first_response_seconds
        );
    }
}
