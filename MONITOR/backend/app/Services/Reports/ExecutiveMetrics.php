<?php

namespace App\Services\Reports;

/**
 * The four executive financial measures, computed the same way for both schemas.
 *
 * Each one is a ratio of figures the drivers already produce, so it lives here
 * rather than in each driver: two implementations of ARPU that disagree by a
 * rounding rule is exactly the sort of divergence that costs a morning in a
 * board meeting.
 *
 * Every measure carries its own basis and assumption in the payload. These are
 * projections, and a projection presented with the same authority as a
 * measurement is the most common way a dashboard misleads — so the UI is given
 * what it needs to say where each number came from.
 */
class ExecutiveMetrics
{
    /**
     * @param float $expectedMrc  monthly charge the active base should bill
     * @param float $collected    what actually came in over the range
     * @param int   $activeSubs   active subscribers right now
     * @param int   $disconnected subscribers whose service has lapsed
     * @param float $lapsedMrc    monthly charge those lapsed accounts carried
     */
    public static function build(
        float $expectedMrc,
        float $collected,
        int $activeSubs,
        int $disconnected,
        float $lapsedMrc,
        string $rangeLabel
    ): array {
        $factor = self::churnFactor();

        return [
            // What the base is contracted to produce next month, independent of
            // what was collected this one. The forward-looking counterpart to
            // income, and the figure a growth target is set against.
            'prospective_revenue' => [
                'label' => 'Prospective Revenue',
                'value' => round($expectedMrc, 2),
                'basis' => 'Active base at plan price, one month',
            ],

            // Per active subscriber, not per account on file: dividing by a base
            // that includes disconnected accounts depresses ARPU every time
            // collections lag, which is the opposite of what the measure is for.
            'arpu' => [
                'label' => 'ARPU',
                'value' => $activeSubs > 0 ? round($expectedMrc / $activeSubs, 2) : null,
                'basis' => $activeSubs > 0
                    ? 'Expected MRC ÷ ' . number_format($activeSubs) . ' active subscribers'
                    : 'No active subscribers to divide by',
            ],

            // Collected against billable. Capped at 999% for the same reason the
            // KPI is: a month carrying back-payments legitimately exceeds 100%,
            // and an uncapped four-figure percentage reads as a fault.
            'collection_efficiency' => [
                'label' => 'Collection Efficiency',
                'value' => $expectedMrc > 0
                    ? min(999.0, round($collected / $expectedMrc * 100, 1))
                    : null,
                'basis' => $expectedMrc > 0
                    ? 'Collected in ' . $rangeLabel . ' ÷ expected MRC'
                    : 'No billable base in this scope',
            ],

            // Monthly revenue at risk from accounts that have already lapsed,
            // discounted by the share historically recovered. The assumption is
            // stated in `basis` because it is the whole content of the number.
            'projected_churn_loss' => [
                'label' => 'Projected Churn Loss',
                'value' => round($lapsedMrc * $factor, 2),
                'basis' => number_format($disconnected) . ' disconnected × '
                    . round($factor * 100) . '% assumed not to return, per month',
                'at_risk_accounts' => $disconnected,
                'at_risk_factor' => $factor,
            ],
        ];
    }

    /** Clamped to 0–1: a factor outside that range is a config typo, not intent. */
    private static function churnFactor(): float
    {
        return max(0.0, min(1.0, (float) config('reporting.churn.at_risk_factor', 0.6)));
    }
}
