<?php

namespace App\Services\Reports;

use App\Models\ExpensePayableStatus;

/**
 * Joins MONITOR's settlement state onto the expense rows a driver synced.
 *
 * The drivers produce the payable *lines* — what is owed, to whom, of what kind
 * — straight from the monitored database, so the ledger always reflects what the
 * operating system currently holds. This class adds the one thing the monitored
 * database cannot be asked to hold: whether finance has marked it paid, which is
 * MONITOR's own record because MONITOR does not write to source systems.
 *
 * Both halves are reported, deliberately. A row shows what the source says it
 * costs *and* what MONITOR says was settled, so a divergence between the two is
 * visible rather than resolved silently in favour of one of them.
 */
class PayablesLedger
{
    /**
     * @param array<int,array{
     *     ref:string, label:string, type:string, amount:float,
     *     count?:int, period_type?:string|null, last_booked_at?:string|null
     * }> $lines expense lines from a driver
     */
    public static function build(string $sourceKey, string $month, array $lines): array
    {
        $monthKey = ExpensePayableStatus::monthKey($month);
        $status = ExpensePayableStatus::forMonth($sourceKey, $monthKey);

        $rows = [];
        $totals = [
            'recurring' => ['count' => 0, 'amount' => 0.0],
            'non_recurring' => ['count' => 0, 'amount' => 0.0],
            'paid' => ['count' => 0, 'amount' => 0.0],
            'unpaid' => ['count' => 0, 'amount' => 0.0],
        ];

        foreach ($lines as $line) {
            $ref = (string) $line['ref'];
            $amount = round((float) $line['amount'], 2);

            $recurrence = ExpenseClassifier::recurrence(
                $line['type'] ?? $line['label'] ?? '',
                $line['period_type'] ?? null
            );

            $settled = $status[$ref] ?? null;
            $isPaid = $settled?->is_paid ?? false;

            $rows[] = [
                'ref' => $ref,
                'label' => (string) $line['label'],
                'type' => (string) ($line['type'] ?? ''),
                'recurrence' => $recurrence,
                'nature' => ExpenseClassifier::nature($line['type'] ?? $line['label'] ?? ''),
                'amount' => $amount,
                'count' => (int) ($line['count'] ?? 1),
                'period_type' => $line['period_type'] ?? null,
                'last_booked_at' => $line['last_booked_at'] ?? null,

                // Settlement, from MONITOR's own table.
                'is_paid' => $isPaid,
                'paid_on' => $settled?->paid_on?->toDateString(),
                'paid_amount' => $settled?->amount !== null ? round((float) $settled->amount, 2) : null,
                'reference' => $settled?->reference,
                'note' => $settled?->note,
                'updated_by' => $settled?->updated_by,
                // True when finance recorded a figure that differs from what the
                // source booked. Surfaced rather than reconciled: which of the
                // two is right is not a question this portal can answer.
                'variance' => $settled?->amount !== null
                    ? round((float) $settled->amount - $amount, 2)
                    : null,
            ];

            $totals[$recurrence]['count']++;
            $totals[$recurrence]['amount'] += $amount;

            $bucket = $isPaid ? 'paid' : 'unpaid';
            $totals[$bucket]['count']++;
            $totals[$bucket]['amount'] += $amount;
        }

        // Unpaid first, then largest: this panel exists to be worked through, and
        // what is already settled is not the part anyone needs at the top.
        usort($rows, function (array $a, array $b) {
            if ($a['is_paid'] !== $b['is_paid']) {
                return $a['is_paid'] ? 1 : -1;
            }

            return $b['amount'] <=> $a['amount'];
        });

        foreach ($totals as $key => $total) {
            $totals[$key]['amount'] = round($total['amount'], 2);
        }

        return [
            'month' => $monthKey,
            'month_label' => date('F Y', strtotime($monthKey)),
            'source' => $sourceKey,
            'rows' => $rows,
            'totals' => $totals,
            'outstanding' => $totals['unpaid']['amount'],
            // Says where the tick lives, so nobody reads a paid flag as something
            // the operating system knows about.
            'settlement_scope' => 'monitor',
        ];
    }
}
