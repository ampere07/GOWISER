<?php

namespace App\Services\Reports;

use Carbon\Carbon;

/**
 * Period rules taken verbatim from the source system's includes/functions.php.
 *
 * These look arbitrary and are not: the branch staff already read reports built
 * on them, and MONITOR exists to agree with those reports. Do not "tidy" the
 * rules here without changing the source system too.
 */
class ReportPeriod
{
    public const GRANULARITIES = ['daily', 'weekly', 'monthly', 'yearly'];

    /**
     * Which expense rows belong in a report of this length.
     *
     * Expenses carry a period_type saying what horizon they were booked
     * against. A month's rent tagged 'monthly' must not be charged against a
     * single day, or that day shows a loss that never happened. Longer reports
     * absorb the shorter horizons; shorter ones never absorb the longer.
     *
     * This is the single most important rule in the whole module.
     *
     * @return string[]
     */
    public static function expenseTypes(string $granularity): array
    {
        switch ($granularity) {
            case 'monthly':
                return ['daily', 'monthly'];
            case 'yearly':
                return ['daily', 'monthly', 'yearly'];
            default:
                return ['daily'];
        }
    }

    public static function normalise(?string $granularity, string $fallback = 'monthly'): string
    {
        return in_array($granularity, self::GRANULARITIES, true) ? $granularity : $fallback;
    }

    /**
     * Infers a report granularity from an arbitrary date range, so an expense
     * row tagged 'monthly' is included when the user picked a whole calendar
     * month but excluded when they picked three days in the middle of it.
     *
     * Port of reportPeriodFromDateRange().
     */
    public static function fromDateRange(string $from, string $to): string
    {
        $fromDate = self::parse($from);
        $toDate = self::parse($to);

        if ($fromDate === null || $toDate === null) {
            return 'daily';
        }

        if ($toDate->lt($fromDate)) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        if ($fromDate->toDateString() === $toDate->toDateString()) {
            return 'daily';
        }

        // A full calendar year: Jan 1 to Dec 31 of the same year.
        if ($fromDate->year === $toDate->year
            && $fromDate->format('m-d') === '01-01'
            && $toDate->format('m-d') === '12-31') {
            return 'yearly';
        }

        // A range starting on the 1st and staying inside one month.
        if ($fromDate->format('Y-m') === $toDate->format('Y-m') && $fromDate->day === 1) {
            return 'monthly';
        }

        return $fromDate->diffInDays($toDate) >= 365 ? 'yearly' : 'daily';
    }

    /**
     * The four windows the Financial Summary shows side by side, anchored on a
     * reference date. Weekly here is the *calendar* week (Mon–Sun), matching
     * modules/reports/financial.php.
     *
     * @return array<string,array{key:string,label:string,from:string,to:string,accent:string,date_label:string}>
     */
    public static function summaryWindows(string $refDate): array
    {
        $ref = self::parse($refDate) ?? Carbon::now();

        $weekStart = $ref->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $ref->copy()->endOfWeek(Carbon::SUNDAY);

        return [
            'daily' => [
                'key' => 'daily',
                'label' => 'Daily',
                'from' => $ref->toDateString(),
                'to' => $ref->toDateString(),
                'accent' => '#0d6efd',
                'date_label' => $ref->format('M d, Y'),
            ],
            'weekly' => [
                'key' => 'weekly',
                'label' => 'Weekly',
                'from' => $weekStart->toDateString(),
                'to' => $weekEnd->toDateString(),
                'accent' => '#0dcaf0',
                'date_label' => $weekStart->format('M d') . ' – ' . $weekEnd->format('M d, Y'),
            ],
            'monthly' => [
                'key' => 'monthly',
                'label' => 'Monthly',
                'from' => $ref->copy()->startOfMonth()->toDateString(),
                'to' => $ref->copy()->endOfMonth()->toDateString(),
                'accent' => '#fd7e14',
                'date_label' => $ref->format('F Y'),
            ],
            'yearly' => [
                'key' => 'yearly',
                'label' => 'Yearly',
                'from' => $ref->copy()->startOfYear()->toDateString(),
                'to' => $ref->copy()->endOfYear()->toDateString(),
                'accent' => '#198754',
                'date_label' => $ref->format('Y'),
            ],
        ];
    }

    /**
     * The KPI window on the dashboard. Weekly here is "the last 7 days", not
     * the calendar week — dashboard_stats.php and financial.php genuinely
     * differ on this, and both are reproduced as-is.
     *
     * @return array{from:string,to:string,label:string}
     */
    public static function dashboardWindow(string $granularity, ?string $asOf = null): array
    {
        $now = self::parse($asOf) ?? Carbon::now();

        switch (self::normalise($granularity, 'daily')) {
            case 'weekly':
                return [
                    'from' => $now->copy()->subDays(7)->toDateString(),
                    'to' => $now->toDateString(),
                    'label' => 'Last 7 Days',
                ];
            case 'monthly':
                return [
                    'from' => $now->copy()->startOfMonth()->toDateString(),
                    'to' => $now->copy()->endOfMonth()->toDateString(),
                    'label' => $now->format('F Y'),
                ];
            case 'yearly':
                return [
                    'from' => $now->copy()->startOfYear()->toDateString(),
                    'to' => $now->copy()->endOfYear()->toDateString(),
                    'label' => $now->format('Y'),
                ];
            default:
                return [
                    'from' => $now->toDateString(),
                    'to' => $now->toDateString(),
                    'label' => 'Today (' . $now->format('M d, Y') . ')',
                ];
        }
    }

    public static function parse(?string $date): ?Carbon
    {
        if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date))) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($date))->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Today, or the supplied anchor when one was given. */
    public static function anchor(?string $asOf = null): Carbon
    {
        return self::parse($asOf) ?? Carbon::now();
    }
}
