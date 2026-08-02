<?php

namespace App\Services\Reports;

/**
 * Two independent classifications of an expense, both driven by its type name.
 *
 *   OpEx / CapEx      what kind of spending it is. Operating expenditure is
 *                     consumed in the period; capital expenditure buys an asset
 *                     that outlives it. Netting one against a month's income is
 *                     correct; netting the other is not, which is why the
 *                     Financial module now reports them apart.
 *
 *   Recurring /       whether it comes back. A payable that recurs needs a
 *   Non-recurring     monthly settlement tick; a one-off does not.
 *
 * The two are orthogonal — a leased vehicle is recurring OpEx, a fibre reel is
 * non-recurring CapEx — so they are separate methods rather than one four-way
 * enum that would force a false choice.
 *
 * Neither source system records either fact. The mapping is name-based and lives
 * in config so a finance team can correct a misclassification without a deploy;
 * an unmatched type defaults to recurring OpEx, which is what the large majority
 * of an ISP's expense ledger actually is.
 */
class ExpenseClassifier
{
    public const OPEX = 'opex';
    public const CAPEX = 'capex';

    public const RECURRING = 'recurring';
    public const NON_RECURRING = 'non_recurring';

    /**
     * Type-name fragments that mark spending as capital.
     *
     * @return string[]
     */
    public static function capexPatterns(): array
    {
        $configured = config('reporting.capex_patterns');

        return is_array($configured) && $configured !== [] ? $configured : [
            'equipment', 'hardware', 'router', 'switch', 'onu', 'olt', 'server',
            'vehicle', 'motorcycle', 'truck', 'tower', 'construction', 'building',
            'fiber', 'fibre', 'cable roll', 'installation asset', 'capital',
            'furniture', 'computer', 'laptop', 'machinery', 'land', 'improvement',
        ];
    }

    /**
     * Type-name fragments that mark an expense as recurring.
     *
     * Deliberately the positive list rather than a list of one-offs: fixed costs
     * are a small, stable, nameable set, whereas the things bought once are
     * unbounded.
     *
     * @return string[]
     */
    public static function recurringPatterns(): array
    {
        $configured = config('reporting.recurring_patterns');

        return is_array($configured) && $configured !== [] ? $configured : [
            'rent', 'rental', 'lease', 'salary', 'salaries', 'payroll', 'wage',
            'electric', 'power', 'water', 'internet', 'bandwidth', 'ip transit',
            'subscription', 'insurance', 'permit', 'license', 'licence',
            'sss', 'philhealth', 'pag-ibig', 'pagibig', 'tax', 'loan', 'amortization',
            'maintenance', 'security', 'janitorial', 'allowance', 'utilities',
            'colocation', 'co-location', 'hosting', 'telephone', 'communication',
        ];
    }

    /**
     * Whether the period_type the source recorded already settles recurrence.
     *
     * NETMANAGER tags each expense with the reporting horizon it was booked
     * against (see ReportPeriod). A row booked 'monthly' or 'yearly' is by
     * construction a fixed cost for that horizon, and that is stronger evidence
     * than any name match — so it is checked first.
     */
    public static function recurrence(?string $typeName, ?string $periodType = null): string
    {
        $period = strtolower(trim((string) $periodType));

        if (in_array($period, ['monthly', 'yearly'], true)) {
            return self::RECURRING;
        }

        return self::matches($typeName, self::recurringPatterns())
            ? self::RECURRING
            : self::NON_RECURRING;
    }

    public static function nature(?string $typeName): string
    {
        return self::matches($typeName, self::capexPatterns()) ? self::CAPEX : self::OPEX;
    }

    private static function matches(?string $typeName, array $patterns): bool
    {
        $value = strtolower(trim((string) $typeName));

        if ($value === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (str_contains($value, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Splits a by-type expense breakdown into OpEx and CapEx totals.
     *
     * Reuses the breakdown the drivers already compute, for the same reason
     * IncomeChannels does: a second query could disagree with the first.
     *
     * @param array<int,array{label:string,count:int,total:float}> $byType
     */
    public static function opexCapex(array $byType): array
    {
        $split = [
            self::OPEX => ['total' => 0.0, 'count' => 0, 'rows' => []],
            self::CAPEX => ['total' => 0.0, 'count' => 0, 'rows' => []],
        ];

        foreach ($byType as $row) {
            $nature = self::nature($row['label'] ?? '');

            $split[$nature]['total'] += (float) ($row['total'] ?? 0);
            $split[$nature]['count'] += (int) ($row['count'] ?? 0);
            $split[$nature]['rows'][] = [
                'label' => (string) ($row['label'] ?? ''),
                'count' => (int) ($row['count'] ?? 0),
                'total' => round((float) ($row['total'] ?? 0), 2),
            ];
        }

        $grand = $split[self::OPEX]['total'] + $split[self::CAPEX]['total'];

        return [
            'opex' => [
                'label' => 'Operating Expenses',
                'total' => round($split[self::OPEX]['total'], 2),
                'count' => $split[self::OPEX]['count'],
                'share_pct' => $grand > 0 ? round($split[self::OPEX]['total'] / $grand * 100, 1) : 0.0,
                'rows' => $split[self::OPEX]['rows'],
            ],
            'capex' => [
                'label' => 'Capital Expenditures',
                'total' => round($split[self::CAPEX]['total'], 2),
                'count' => $split[self::CAPEX]['count'],
                'share_pct' => $grand > 0 ? round($split[self::CAPEX]['total'] / $grand * 100, 1) : 0.0,
                'rows' => $split[self::CAPEX]['rows'],
            ],
            'total' => round($grand, 2),
        ];
    }
}
