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

        $cursor = $start->copy()->setTimezone($timezone);
        $endAt = $end->copy()->setTimezone($timezone);
        $total = 0;

        while ($cursor->lt($endAt)) {
            $next = $cursor->copy()->addMinute();

            if ($this->isBusinessMinute($cursor, $workingDays, $workdayStart, $workdayEnd, $lunchStart, $lunchEnd)) {
                $remaining = $cursor->diffInSeconds($endAt, true);
                $total += (int) min(60, $remaining);
            }

            $cursor = $next;
        }

        return max(0, $total);
    }

    /**
     * @param int[] $workingDays
     */
    private function isBusinessMinute(
        Carbon $moment,
        array $workingDays,
        string $workdayStartTime,
        string $workdayEndTime,
        ?string $lunchStartTime,
        ?string $lunchEndTime,
    ): bool {
        if (!in_array($moment->dayOfWeekIso, $workingDays, true)) {
            return false;
        }

        $workStart = Carbon::parse($moment->toDateString() . " " . $workdayStartTime, $moment->getTimezone());
        $workEnd = Carbon::parse($moment->toDateString() . " " . $workdayEndTime, $moment->getTimezone());

        if ($moment->lt($workStart) || $moment->gte($workEnd)) {
            return false;
        }

        if ($lunchStartTime && $lunchEndTime) {
            $lunchStart = Carbon::parse($moment->toDateString() . " " . $lunchStartTime, $moment->getTimezone());
            $lunchEnd = Carbon::parse($moment->toDateString() . " " . $lunchEndTime, $moment->getTimezone());

            if ($moment->gte($lunchStart) && $moment->lt($lunchEnd)) {
                return false;
            }
        }

        return true;
    }
}
