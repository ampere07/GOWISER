<?php

namespace App\Services\Reports;

/**
 * Rolls a source system's free-text workflow statuses into reporting buckets.
 *
 * SYNC writes `applications.status`, `job_orders.onsite_status` and
 * `service_orders.support_status` as strings an operator picks from a dropdown,
 * and the Executive Dashboard reports in a different, smaller vocabulary — new
 * apply, rescheduled, in progress, failed, done. Something has to translate, and
 * doing it here rather than in SQL buys three things:
 *
 *  - the queries stay a plain GROUP BY, so they use the index and return one row
 *    per status instead of one CASE expression per bucket;
 *  - the mapping is a config line (reporting.application_buckets,
 *    reporting.work_order_buckets), so a status added to SYNC's dropdown does not
 *    need a deploy;
 *  - the *unbucketed* residue survives. A status in no bucket is counted in
 *    `other` and still appears in the breakdown, rather than being folded into
 *    whichever bucket happened to be the default. That residue is the signal that
 *    a new workflow state appeared, and absorbing it hides exactly that.
 *
 * Matching is on the whole trimmed, lower-cased status rather than on substrings:
 * 'no slot' and 'slot' would otherwise both claim the same rows, and first-match
 * order would silently decide which.
 */
class StatusBuckets
{
    /**
     * The bucket a raw status belongs to, or null when nothing claims it.
     *
     * @param array<string,string[]> $buckets bucket key => accepted raw values
     */
    public static function classify(?string $raw, array $buckets): ?string
    {
        $value = strtolower(trim((string) $raw));

        if ($value === '') {
            return null;
        }

        foreach ($buckets as $bucket => $members) {
            foreach ((array) $members as $member) {
                if ($value === strtolower(trim((string) $member))) {
                    return (string) $bucket;
                }
            }
        }

        return null;
    }

    /**
     * Totals per bucket for a {status => count} map.
     *
     * Every configured bucket is present in the result, at zero when nothing
     * matched: a bucket that vanishes from a four-tile row reads as a loading
     * failure rather than as "none of these". `other` and `total` are always
     * added, so the tiles can be checked against the total on screen.
     *
     * @param array<string,int>      $counts  raw status => count
     * @param array<string,string[]> $buckets bucket key => accepted raw values
     * @return array<string,int>
     */
    public static function tally(array $counts, array $buckets): array
    {
        $out = array_fill_keys(array_keys($buckets), 0);
        $out['other'] = 0;
        $total = 0;

        foreach ($counts as $raw => $count) {
            $count = (int) $count;
            $total += $count;

            $bucket = self::classify((string) $raw, $buckets) ?? 'other';
            $out[$bucket] += $count;
        }

        $out['total'] = $total;

        return $out;
    }

    /**
     * The same tally taken from a driver's `[{label, count}]` status list.
     *
     * @param array<int,array{label?:string,count?:int}> $rows
     * @param array<string,string[]>                     $buckets
     * @return array<string,int>
     */
    public static function tallyRows(array $rows, array $buckets): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $label = (string) ($row['label'] ?? '');

            if ($label === '') {
                continue;
            }

            $counts[$label] = ($counts[$label] ?? 0) + (int) ($row['count'] ?? 0);
        }

        return self::tally($counts, $buckets);
    }

    /** @return array<string,string[]> */
    public static function applications(): array
    {
        $configured = config('reporting.application_buckets');

        return is_array($configured) && $configured !== [] ? $configured : [
            'rescheduled' => ['reschedule', 'rescheduled'],
            'in_progress' => ['in progress', 'schedule', 'scheduled'],
            'new_apply' => ['new apply', 'pending'],
            'failed' => ['failed', 'cancelled', 'no facility', 'no slot', 'duplicate'],
        ];
    }

    /**
     * The Executive Dashboard's partition of the same application statuses.
     *
     * Divided along a different line from applications() — "no facility" is a
     * pending action here rather than a failure — and both divisions are
     * reported, of the same rows, for different readers. See the config block
     * for why that is deliberate rather than duplication.
     *
     * @return array<string,string[]>
     */
    public static function applicationCadence(): array
    {
        $configured = config('reporting.application_cadence_buckets');

        return is_array($configured) && $configured !== [] ? $configured : [
            'newly_applied' => ['new apply', 'new', 'submitted', 'applied'],
            'scheduled_for_setup' => ['in progress', 'scheduled', 'schedule', 'processed', 'rescheduled', 'reschedule'],
            'to_be_processed' => ['pending', 'for approval'],
            'no_facility' => ['no facility', 'no slot'],
            'cancelled' => ['cancelled', 'canceled', 'rejected', 'failed', 'duplicate'],
        ];
    }

    /** @return array<string,string[]> */
    public static function workOrders(): array
    {
        $configured = config('reporting.work_order_buckets');

        return is_array($configured) && $configured !== [] ? $configured : [
            'done' => ['done', 'completed', 'resolved', 'approved'],
            'reschedule' => ['reschedule', 'rescheduled'],
            'failed' => ['failed', 'cancelled'],
            'in_progress' => ['in progress', 'pending', 'new', 'open'],
        ];
    }

    /**
     * Total Applications, as the brief defines it.
     *
     *     (rescheduled + in progress + new apply) − failed
     *
     * Floored at zero. The subtraction can go negative on a range where more
     * applications failed than were opened — a week spent clearing a backlog of
     * duplicates does exactly that — and a negative application count is not a
     * quantity of anything an executive can act on.
     *
     * @param array<string,int> $tally from tally() against applications()
     */
    public static function applicationTotal(array $tally): int
    {
        $live = (int) ($tally['rescheduled'] ?? 0)
            + (int) ($tally['in_progress'] ?? 0)
            + (int) ($tally['new_apply'] ?? 0);

        return max(0, $live - (int) ($tally['failed'] ?? 0));
    }
}
