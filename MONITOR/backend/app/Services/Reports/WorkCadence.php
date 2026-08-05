<?php

namespace App\Services\Reports;

use Carbon\Carbon;

/**
 * The same work queue counted over three windows at once: today, this week, this
 * month.
 *
 * The Executive Dashboard used to answer "how many installations?" for whatever
 * range the viewer had selected, which meant the answer depended on a control
 * most readers never touched — and two people quoting the screen were often
 * quoting different windows. The brief replaces that with three fixed columns
 * per figure, so "Installed Today" and "Installed This Month" sit side by side
 * and neither can be mistaken for the other.
 *
 * ── Why one query rather than three ───────────────────────────────────
 *
 * Three windows do not need three round trips. The union of them is a single
 * contiguous span — from the earlier of (start of month, start of week) up to
 * the anchor — so a driver fetches that span *once*, grouped by day and status,
 * and this class folds the daily rows into the three tallies. A fleet of a dozen
 * branch databases pays one pass per queue instead of three, which is the
 * difference between a dashboard that opens and one that times out.
 *
 * ── Which timestamp a row is counted on ───────────────────────────────
 *
 * `updated_at`, falling back to `timestamp` then `created_at` — the brief's
 * rule, and the only one that makes the labels true. "Installed Today" is a
 * claim about work that *finished* today; counting on the creation date would
 * report a job opened three weeks ago and closed this morning as neither.
 *
 * ── Why the windows do not always nest ────────────────────────────────
 *
 * Week is week-to-date and month is month-to-date, both bounded by the anchor,
 * so neither ever counts work dated after the "as of" point. On the first days
 * of a month that makes the week figure legitimately *larger* than the month
 * figure: on Saturday 1 August, this month is one day old while this week
 * reaches back into July. That is the honest reading of both labels, and
 * stretching the month backwards to preserve a tidy nesting would misreport it.
 */
class WorkCadence
{
    /** The three windows, in the order they are read. */
    public const WINDOWS = ['today', 'week', 'month'];

    /**
     * Column candidates for "when did this row last change", best first.
     *
     * Drivers intersect this with the columns a schema actually has, so a source
     * missing `updated_at` still reports on `timestamp` rather than failing.
     */
    public const DATE_COLUMNS = ['updated_at', 'timestamp', 'created_at'];

    /**
     * The three windows as inclusive date pairs, plus a label for each.
     *
     * @return array<string,array{key:string,label:string,from:string,to:string}>
     */
    public static function windows(Carbon $anchor): array
    {
        $day = $anchor->copy()->startOfDay();

        return [
            'today' => [
                'key' => 'today',
                'label' => 'Today',
                'from' => $day->toDateString(),
                'to' => $day->toDateString(),
            ],
            'week' => [
                'key' => 'week',
                'label' => 'This Week',
                'from' => $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                'to' => $day->toDateString(),
            ],
            'month' => [
                'key' => 'month',
                'label' => 'This Month',
                'from' => $day->copy()->startOfMonth()->toDateString(),
                'to' => $day->toDateString(),
            ],
        ];
    }

    /**
     * The earliest date any of the three windows reaches.
     *
     * This is the single span a driver fetches. It is the start of the week
     * rather than the start of the month whenever the week crosses the boundary
     * — see the class note on nesting.
     */
    public static function floor(Carbon $anchor): Carbon
    {
        $day = $anchor->copy()->startOfDay();

        return $day->copy()->startOfMonth()->min($day->copy()->startOfWeek(Carbon::MONDAY));
    }

    /**
     * Folds day-and-status rows into a bucket tally per window.
     *
     * Each row is `{day: 'Y-m-d', label: <raw status>, count: n}`. A row lands in
     * every window whose span contains its day, so one job counts once in today,
     * once in this week and once in this month — those are three answers to three
     * questions, not double counting.
     *
     * @param  array<int,array{day?:string,label?:string,count?:int}> $rows
     * @param  array<string,string[]>                                 $buckets bucket key => raw statuses
     * @return array<string,array<string,int>>                        window => tally
     */
    public static function tally(array $rows, array $buckets, Carbon $anchor): array
    {
        $windows = self::windows($anchor);
        $counts = array_fill_keys(array_keys($windows), []);

        foreach ($rows as $row) {
            $day = trim((string) ($row['day'] ?? ''));
            $label = (string) ($row['label'] ?? '');
            $count = (int) ($row['count'] ?? 0);

            if ($day === '' || $label === '') {
                continue;
            }

            foreach ($windows as $key => $window) {
                if ($day >= $window['from'] && $day <= $window['to']) {
                    $counts[$key][$label] = ($counts[$key][$label] ?? 0) + $count;
                }
            }
        }

        $out = [];

        // StatusBuckets::tally fills every configured bucket, so a window with no
        // rows renders as zeros rather than as missing tiles — a tile that
        // vanishes reads as a broken panel, not as "none of these".
        foreach ($counts as $key => $statusCounts) {
            $out[$key] = StatusBuckets::tally($statusCounts, $buckets);
        }

        return $out;
    }

    /**
     * An empty cadence, shaped exactly like a real one.
     *
     * Used by sources that model none of these queues, so the frontend reads
     * zeros of the right shape instead of having to special-case a null.
     *
     * @param  array<string,string[]> $buckets
     * @return array<string,array<string,int>>
     */
    public static function blank(array $buckets): array
    {
        $empty = StatusBuckets::tally([], $buckets);

        return array_fill_keys(self::WINDOWS, $empty);
    }

    /**
     * Adds two cadences together, window by window and bucket by bucket.
     *
     * Used when merging one branch database into the fleet total. Buckets absent
     * from either side are treated as zero rather than dropped, so a schema that
     * never produces "rescheduled" cannot delete that bucket from the fleet
     * figure.
     *
     * @param  array<string,array<string,int>> $into
     * @param  array<string,array<string,int>> $from
     * @return array<string,array<string,int>>
     */
    public static function merge(array $into, array $from): array
    {
        foreach ($from as $window => $tally) {
            if (!is_array($tally)) {
                continue;
            }

            foreach ($tally as $bucket => $count) {
                $into[$window][$bucket] = ($into[$window][$bucket] ?? 0) + (int) $count;
            }
        }

        return $into;
    }
}
