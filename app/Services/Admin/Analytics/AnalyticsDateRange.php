<?php

namespace App\Services\Admin\Analytics;

use Illuminate\Support\Carbon;

final class AnalyticsDateRange
{
    public const MAX_CUSTOM_DAYS = 366;

    /** @return array{0: Carbon, 1: Carbon} */
    public static function normalize(Carbon $from, Carbon $to, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $from = $from->min($today)->startOfDay();
        $to = $to->min($today)->startOfDay();

        if ($from->greaterThan($to)) {
            $from = $to->copy();
        }

        $earliestAllowed = $to->copy()->subDays(self::MAX_CUSTOM_DAYS - 1);
        if ($from->lessThan($earliestAllowed)) {
            $from = $earliestAllowed;
        }

        return [$from, $to];
    }
}
