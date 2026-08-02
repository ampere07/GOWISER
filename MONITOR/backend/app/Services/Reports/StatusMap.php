<?php

namespace App\Services\Reports;

/**
 * The subscriber status vocabulary this portal reports in.
 *
 * The two monitored systems write their own free-text status strings and neither
 * matches what management asks for. Rather than renaming columns in systems
 * MONITOR is not allowed to write to, the translation happens here, once, and
 * both drivers go through it — which is what stops "Suspended" appearing on one
 * page and "Restricted" on the next.
 *
 * Three rules, all of them from the brief:
 *
 *   Pending    excluded entirely. An application that has not been activated is
 *              not a subscriber, and counting it inflates the base.
 *   Suspended  presented as Restricted.
 *   Expired    presented as Disconnected.
 *
 * Exclusion is applied to the *reported* counts, not to the underlying query, so
 * a pending row still exists and still appears in the operational queues where
 * it belongs. This class only governs how the subscriber base is stated.
 */
class StatusMap
{
    /** Raw status values that must never reach a subscriber figure. */
    public const EXCLUDED = ['pending', 'in progress', 'for activation'];

    /**
     * Raw value (lower-cased, trimmed) to the label this portal uses.
     *
     * Both spellings of each source's vocabulary are listed because GOWISER
     * writes 'overdue' where NETMANAGER writes 'expired', and they mean the same
     * thing to a reader of this dashboard.
     */
    public const LABELS = [
        'active' => 'Active',
        'vip' => 'VIP',
        'suspended' => 'Restricted',
        'restricted' => 'Restricted',
        'expired' => 'Disconnected',
        'overdue' => 'Disconnected',
        'disconnected' => 'Disconnected',
        'inactive' => 'Inactive',
        'pullout' => 'Pullout',
        'pulled out' => 'Pullout',
        'cancelled' => 'Inactive',
        'closed' => 'Inactive',
    ];

    /**
     * The four buckets the Billing Status summary header reports, and the raw
     * values that roll into each.
     *
     * VIP is its own bucket rather than a flavour of Active: it is a distinct
     * billing status in both systems and the whole point of the header is that
     * management can see it separately.
     */
    public const BILLING_BUCKETS = [
        'active' => ['active'],
        'vip' => ['vip'],
        'inactive' => ['inactive', 'cancelled', 'closed'],
        'pullout' => ['pullout', 'pulled out'],
    ];

    public static function normalise(?string $raw): string
    {
        return strtolower(trim((string) $raw));
    }

    /** True when this status must not be counted as a subscriber at all. */
    public static function isExcluded(?string $raw): bool
    {
        return in_array(self::normalise($raw), self::EXCLUDED, true);
    }

    /**
     * The display label for a raw status.
     *
     * An unrecognised status is title-cased and passed through rather than
     * dropped or bucketed as "Other": a new workflow state added in the source
     * system must still appear on the chart, or it vanishes silently and nobody
     * finds out until the totals stop adding up.
     */
    public static function label(?string $raw): string
    {
        $key = self::normalise($raw);

        if ($key === '') {
            return 'Unspecified';
        }

        return self::LABELS[$key] ?? ucwords($key);
    }

    /**
     * Rewrites a raw {status => count} map into the reported vocabulary.
     *
     * Excluded statuses are dropped, and statuses that map onto the same label
     * are summed — 'expired' and 'overdue' both become Disconnected, and showing
     * them as two slices of the same pie would be a distinction the reader
     * cannot act on.
     *
     * @param array<string,int> $counts
     * @return array<string,int>
     */
    public static function rewrite(array $counts): array
    {
        $out = [];

        foreach ($counts as $raw => $count) {
            if (self::isExcluded($raw)) {
                continue;
            }

            $label = self::label($raw);
            $out[$label] = ($out[$label] ?? 0) + (int) $count;
        }

        arsort($out);

        return $out;
    }

    /**
     * The Billing Status summary header: Active, VIP, Inactive, Pullout.
     *
     * @param array<string,int> $counts raw {status => count}
     * @return array{active:int,vip:int,inactive:int,pullout:int,total:int}
     */
    public static function billingSummary(array $counts): array
    {
        $summary = ['active' => 0, 'vip' => 0, 'inactive' => 0, 'pullout' => 0];

        foreach ($counts as $raw => $count) {
            $key = self::normalise($raw);

            foreach (self::BILLING_BUCKETS as $bucket => $members) {
                if (in_array($key, $members, true)) {
                    $summary[$bucket] += (int) $count;
                }
            }
        }

        // Total is the four buckets, not every row: it is the header's own
        // subtotal, and a total larger than the parts it sits above reads as an
        // arithmetic error rather than as a wider scope.
        $summary['total'] = array_sum($summary);

        return $summary;
    }

    /**
     * A SQL fragment counting rows whose status falls in a billing bucket.
     *
     * Used where the count has to be produced per group — the barangay table has
     * one row per barangay and pulling every subscriber into PHP to bucket them
     * would be thousands of rows for a table that is already an aggregate.
     *
     * @param string $column already-qualified column expression
     */
    public static function bucketSql(string $column, string $bucket): string
    {
        $members = self::BILLING_BUCKETS[$bucket] ?? [];

        if ($members === []) {
            return '0';
        }

        $quoted = implode(', ', array_map(fn ($value) => "'" . $value . "'", $members));

        return "COALESCE(SUM(LOWER(TRIM(COALESCE({$column}, ''))) IN ({$quoted})), 0)";
    }

    /** SQL predicate excluding the statuses that are not subscribers. */
    public static function excludeSql(string $column): string
    {
        $quoted = implode(', ', array_map(fn ($value) => "'" . $value . "'", self::EXCLUDED));

        return "LOWER(TRIM(COALESCE({$column}, ''))) NOT IN ({$quoted})";
    }
}
