<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ExpensePayableStatus;
use App\Services\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The monthly paid/unpaid toggle on the Accounts Payable panel.
 *
 * The only endpoint outside the Databases page that writes, and — like that one
 * — it writes to MONITOR's own table, never to a monitored database. The
 * read-only guarantee on source systems is enforced at the connection level in
 * SourceRegistry::connection() and is not weakened for a checkbox.
 *
 * That has a consequence worth being explicit about: MONITOR's record of what
 * was settled can diverge from what the operating system's own ledger says. The
 * payload reports both, and the panel shows the variance, rather than resolving
 * it silently in favour of whichever was written last.
 *
 * Behind `permission:action.payables.toggle` — a distinct grant from being able
 * to *read* the payables widget, because approving a settlement and looking at
 * one are different jobs.
 */
class PayableController extends Controller
{
    public function __construct(private SourceRegistry $sources)
    {
    }

    /**
     * Marks one payable paid or unpaid for a month.
     *
     * Idempotent by (source, ref, month): pressing the toggle twice lands on the
     * same row rather than accumulating history, which is what the audit trail is
     * for.
     */
    public function toggle(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', Rule::in($this->sources->keys())],
            'ref' => ['required', 'string', 'max:191'],
            'month' => ['required', 'date_format:Y-m-d'],
            'is_paid' => ['required', 'boolean'],
            'label' => ['nullable', 'string', 'max:191'],
            // What finance actually paid, when it differs from what the source
            // booked. Nullable: most of the time it does not differ, and forcing
            // a figure would invite someone to retype the booked one.
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $month = ExpensePayableStatus::monthKey($data['month']);
        $user = $request->user();

        $existing = ExpensePayableStatus::where('source_key', $data['source'])
            ->where('expense_ref', $data['ref'])
            ->whereDate('period_month', $month)
            ->first();

        $before = $existing
            ? ['is_paid' => $existing->is_paid, 'amount' => $existing->amount, 'paid_on' => $existing->paid_on?->toDateString()]
            : [];

        $status = ExpensePayableStatus::updateOrCreate(
            [
                'source_key' => $data['source'],
                'expense_ref' => $data['ref'],
                'period_month' => $month,
            ],
            [
                'is_paid' => $data['is_paid'],
                // Cleared when unticking. A paid_on left behind on an unpaid row
                // is the kind of stale field that later gets read as a payment.
                'paid_on' => $data['is_paid']
                    ? ($data['paid_on'] ?? now()->toDateString())
                    : null,
                'amount' => $data['is_paid'] ? ($data['amount'] ?? null) : null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'updated_by_user_id' => $user?->id,
                'updated_by' => $user?->username,
            ]
        );

        AuditLog::record(
            $request,
            $data['is_paid'] ? 'payable.paid' : 'payable.unpaid',
            ExpensePayableStatus::class,
            $status->id,
            sprintf(
                'Marked %s as %s for %s on [%s]',
                $data['label'] ?? $data['ref'],
                $data['is_paid'] ? 'PAID' : 'UNPAID',
                date('F Y', strtotime($month)),
                $data['source']
            ),
            AuditLog::diff($before, [
                'is_paid' => $data['is_paid'],
                'amount' => $data['amount'] ?? null,
                'paid_on' => $status->paid_on?->toDateString(),
            ])
        );

        return response()->json([
            'status' => 'success',
            'message' => $data['is_paid'] ? 'Marked as paid.' : 'Marked as unpaid.',
            'data' => [
                'ref' => $status->expense_ref,
                'source' => $status->source_key,
                'month' => $month,
                'is_paid' => $status->is_paid,
                'paid_on' => $status->paid_on?->toDateString(),
                'amount' => $status->amount !== null ? round((float) $status->amount, 2) : null,
                'reference' => $status->reference,
                'note' => $status->note,
                'updated_by' => $status->updated_by,
            ],
        ]);
    }
}
