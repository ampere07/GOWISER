<?php

namespace App\Services\Reports;

/**
 * Splits collections into the three channels finance reconciles against:
 * Cash, PNB, and Xendit.
 *
 * Neither source system stores a channel column — both store a free-text payment
 * method that cashiers and gateways write in a dozen spellings. The mapping
 * therefore lives in config (`reporting.income_channels`) rather than in SQL, so
 * a new gateway name is a config line rather than a deploy of changed queries.
 *
 * Anything unmatched lands in `other` rather than being forced into Cash. A
 * channel total that quietly absorbs unrecognised methods is worse than one that
 * shows a residue, because the residue is the thing that tells finance a new
 * payment method appeared.
 */
class IncomeChannels
{
    /** Order the channels are reported in, and their display labels. */
    public const CHANNELS = [
        'cash' => 'Cash',
        'pnb' => 'PNB',
        'xendit' => 'Xendit',
        'other' => 'Other',
    ];

    /**
     * Patterns per channel, matched case-insensitively as substrings against the
     * method string. First channel that matches wins, in CHANNELS order — so a
     * method recorded as "PNB CASH DEPOSIT" is a bank collection, not cash over
     * the counter, only if 'pnb' is listed before 'cash' in config.
     *
     * @return array<string,string[]>
     */
    public static function patterns(): array
    {
        $configured = config('reporting.income_channels');

        return is_array($configured) && $configured !== [] ? $configured : [
            'pnb' => ['pnb', 'philippine national bank', 'bank transfer', 'bank deposit'],
            'xendit' => ['xendit', 'portal', 'online', 'gcash', 'maya', 'paymaya', 'e-wallet', 'ewallet'],
            'cash' => ['cash', 'over the counter', 'otc', 'walk-in', 'office'],
        ];
    }

    /**
     * Which channel a raw payment-method string belongs to.
     *
     * Bank patterns are tested before cash on purpose: "PNB cash deposit"
     * contains both words and is a bank collection.
     */
    public static function classify(?string $method): string
    {
        $value = strtolower(trim((string) $method));

        if ($value === '') {
            return 'other';
        }

        foreach (self::patterns() as $channel => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($value, strtolower($needle))) {
                    return $channel;
                }
            }
        }

        return 'other';
    }

    /**
     * Rolls a by-method breakdown into the three channels.
     *
     * Takes the breakdown the drivers already compute rather than issuing its own
     * query: the split is a regrouping of the same rows, and a second query
     * against payments could disagree with the first if a row is written between
     * them.
     *
     * @param array<int,array{label:string,count:int,total:float}> $byMethod
     * @return array<int,array{key:string,label:string,count:int,total:float,share_pct:float,methods:string[]}>
     */
    public static function summarise(array $byMethod): array
    {
        $buckets = [];

        foreach (array_keys(self::CHANNELS) as $channel) {
            $buckets[$channel] = ['count' => 0, 'total' => 0.0, 'methods' => []];
        }

        foreach ($byMethod as $row) {
            $channel = self::classify($row['label'] ?? '');

            $buckets[$channel]['count'] += (int) ($row['count'] ?? 0);
            $buckets[$channel]['total'] += (float) ($row['total'] ?? 0);

            if (($row['label'] ?? '') !== '') {
                $buckets[$channel]['methods'][] = (string) $row['label'];
            }
        }

        $grand = array_sum(array_column($buckets, 'total'));

        $out = [];

        foreach (self::CHANNELS as $key => $label) {
            $bucket = $buckets[$key];

            // 'other' is dropped when empty; the three named channels always
            // appear, at zero if need be, because a missing channel on a
            // three-column summary reads as a loading failure.
            if ($key === 'other' && $bucket['total'] <= 0 && $bucket['count'] === 0) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => $label,
                'count' => $bucket['count'],
                'total' => round($bucket['total'], 2),
                'share_pct' => $grand > 0 ? round($bucket['total'] / $grand * 100, 1) : 0.0,
                // The methods that rolled into this channel, so an unexpected
                // total can be traced without opening the source system.
                'methods' => array_values(array_unique($bucket['methods'])),
            ];
        }

        return $out;
    }
}
