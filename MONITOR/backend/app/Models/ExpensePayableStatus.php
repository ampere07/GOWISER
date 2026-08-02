<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MONITOR's own record of whether a synced expense has been settled this month.
 *
 * Kept here rather than written back to the source system because the read-only
 * guarantee on monitored databases is enforced at the connection level and is
 * not negotiable for a checkbox. The expense rows themselves stay live from the
 * source — sync visibility is preserved — and this table carries only the
 * settlement state finance confirms.
 *
 * Keyed per month: a recurring payable is payable again next month, and last
 * month's tick must not mark this month's rent as settled.
 */
class ExpensePayableStatus extends Model
{
    protected $table = 'expense_payable_status';

    protected $fillable = [
        'source_key',
        'expense_ref',
        'period_month',
        'is_paid',
        'paid_on',
        'amount',
        'reference',
        'note',
        'updated_by_user_id',
        'updated_by',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'period_month' => 'date',
        'paid_on' => 'date',
        'amount' => 'decimal:2',
    ];

    /** Normalises any date inside a month to that month's first day. */
    public static function monthKey(?string $date): string
    {
        $parsed = $date ? strtotime($date) : false;

        return date('Y-m-01', $parsed !== false ? $parsed : time());
    }

    /**
     * Settlement state for one source and month, keyed by expense reference.
     *
     * One query for the whole panel rather than one per row: a payables ledger
     * is twenty to fifty rows and N+1 lookups on a page that already fans out
     * across databases is the difference between a fast page and a slow one.
     *
     * @return array<string,self>
     */
    public static function forMonth(string $sourceKey, string $month): array
    {
        return static::where('source_key', $sourceKey)
            ->whereDate('period_month', self::monthKey($month))
            ->get()
            ->keyBy('expense_ref')
            ->all();
    }
}
