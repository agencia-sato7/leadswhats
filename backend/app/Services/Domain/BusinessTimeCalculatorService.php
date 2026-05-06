<?php

namespace App\Services\Domain;

use App\Models\Company;
use Carbon\Carbon;

class BusinessTimeCalculatorService
{
    public function calculateBusinessSeconds(Carbon $start, Carbon $end, Company $company): int
    {
        if ($end->lte($start)) {
            return 0;
        }

        $cursor = $start->copy();
        $total = 0;

        while ($cursor->lt($end)) {
            $next = $cursor->copy()->addMinute();

            if ($this->isBusinessMinute($cursor, $company)) {
                $remaining = $cursor->diffInSeconds($end, true);
                $total += (int) min(60, $remaining);
            }

            $cursor = $next;
        }

        return max(0, $total);
    }

    private function isBusinessMinute(Carbon $moment, Company $company): bool
    {
        if ($moment->isWeekend()) {
            return false;
        }

        $workStart = Carbon::parse($moment->toDateString() . ' ' . $company->work_start);
        $workEnd = Carbon::parse($moment->toDateString() . ' ' . $company->work_end);

        if ($moment->lt($workStart) || $moment->gte($workEnd)) {
            return false;
        }

        if ($company->lunch_start && $company->lunch_end) {
            $lunchStart = Carbon::parse($moment->toDateString() . ' ' . $company->lunch_start);
            $lunchEnd = Carbon::parse($moment->toDateString() . ' ' . $company->lunch_end);

            if ($moment->gte($lunchStart) && $moment->lt($lunchEnd)) {
                return false;
            }
        }

        return true;
    }
}
