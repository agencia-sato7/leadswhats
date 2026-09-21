<?php

namespace App\Services\Domain;

use App\Models\Company;
use App\Services\CompanySettingsService;
use Carbon\Carbon;

class BusinessTimeCalculatorService
{
    public function __construct(private readonly CompanySettingsService $settings)
    {
    }

    public function calculateBusinessSeconds(Carbon $start, Carbon $end, Company $company): int
    {
        if ($end->lte($start)) {
            return 0;
        }

        $timezone = $this->settings->timezone($company->id);
        $workingDays = $this->settings->workingDays($company->id);
        $workdayStart = $this->settings->workdayStartTime($company->id);
        $workdayEnd = $this->settings->workdayEndTime($company->id);
        $lunchStart = $this->settings->lunchStartTime($company->id);
        $lunchEnd = $this->settings->lunchEndTime($company->id);

        $startAt = $start->copy()->setTimezone($timezone);
        $endAt = $end->copy()->setTimezone($timezone);
        $day = $startAt->copy()->startOfDay();
        $total = 0.0;

        // Visit days, not every elapsed minute. Old unanswered conversations must
        // not become progressively more expensive on every dashboard refresh.
        while ($day->lt($endAt)) {
            if (in_array($day->dayOfWeekIso, $workingDays, true)) {
                $date = $day->toDateString();
                $from = Carbon::parse($date . ' ' . $workdayStart, $timezone)->max($startAt);
                $to = Carbon::parse($date . ' ' . $workdayEnd, $timezone)->min($endAt);

                if ($to->gt($from)) {
                    $seconds = $from->diffInSeconds($to, true);
                    if ($lunchStart && $lunchEnd) {
                        $breakFrom = Carbon::parse($date . ' ' . $lunchStart, $timezone)->max($from);
                        $breakTo = Carbon::parse($date . ' ' . $lunchEnd, $timezone)->min($to);
                        if ($breakTo->gt($breakFrom)) {
                            $seconds -= $breakFrom->diffInSeconds($breakTo, true);
                        }
                    }
                    $total += $seconds;
                }
            }
            $day->addDay()->startOfDay();
        }

        return max(0, (int) $total);
    }
}
