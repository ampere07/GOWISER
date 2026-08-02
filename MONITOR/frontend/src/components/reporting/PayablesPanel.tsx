import React, { useState } from 'react';
import { AlertTriangle, CheckCircle2, Circle, Info, Loader2, Receipt, RefreshCw } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { usePermissions } from '../../hooks/usePermissions';
import { adminService } from '../../services/adminService';
import { PayablesLedger, PayableRow, Recurrence } from '../../types/reporting';
import { ACTION } from '../../types/rbac';
import { formatDate, formatMoney, pluralise } from '../../utils/format';
import Card, { CardHeader } from './Card';
import { Pill, Table, TableState, Td, Th, Thead, TotalRow, Tr } from './primitives';

interface PayablesPanelProps {
  ledger: PayablesLedger | null;
  loading: boolean;
  error: string | null;
  /** Bumped after a successful toggle so the page refetches. */
  onChanged: () => void;
  actions?: React.ReactNode;
}

const RECURRENCE_LABEL: Record<Recurrence, string> = {
  recurring: 'Recurring',
  non_recurring: 'One-off',
};

/**
 * Accounts Payable and Recurring Expenses.
 *
 * Two halves from two places, and the panel is explicit about which is which:
 *
 *  - the *obligations* come live from the monitored database, so what is owed
 *    and to whom is always what the operating system currently holds
 *  - the *settlement* — the paid tick — is MONITOR's own record, because MONITOR
 *    never writes to a source system
 *
 * That means the two can disagree, and where they do the variance is shown
 * rather than reconciled. Which of the two is right is not a question a
 * monitoring portal can answer, and quietly preferring one would hide the very
 * discrepancy someone needs to chase.
 *
 * The toggle is behind `action.payables.toggle`, separately from being able to
 * *read* this panel: approving a settlement and looking at one are different
 * jobs. Without it the state still renders, as a static badge.
 */
const PayablesPanel: React.FC<PayablesPanelProps> = ({
  ledger,
  loading,
  error,
  onChanged,
  actions,
}) => {
  const isDarkMode = useTheme();
  const { can } = usePermissions();

  const [pending, setPending] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [filter, setFilter] = useState<'all' | Recurrence>('all');

  const editable = can(ACTION.payableToggle);
  const rows = ledger?.rows ?? [];

  const visible = filter === 'all' ? rows : rows.filter((row) => row.recurrence === filter);

  const toggle = async (row: PayableRow) => {
    if (!editable || !ledger) return;

    // Namespaced by source: two branches can both owe rent, and the key has to
    // address the right one.
    const key = `${row.source ?? ledger.source ?? ''}:${row.ref}`;

    setPending(key);
    setFailure(null);

    try {
      await adminService.togglePayable({
        source: row.source ?? ledger.source ?? '',
        ref: row.ref,
        month: ledger.month,
        isPaid: !row.is_paid,
        label: row.label,
      });

      onChanged();
    } catch (err: any) {
      setFailure(
        err?.response?.data?.message ?? 'Could not update this payable. Try again.'
      );
    } finally {
      setPending(null);
    }
  };

  const totals = ledger?.totals;

  return (
    <Card flush>
      <CardHeader
        title="Accounts Payable & Recurring Expenses"
        subtitle={ledger ? `Settlement for ${ledger.month_label}` : undefined}
        icon={<Receipt size={16} />}
        actions={
          <div className="flex flex-wrap items-center gap-2 justify-end">
            <div
              role="group"
              aria-label="Filter by recurrence"
              className={`inline-flex rounded-lg border overflow-hidden ${
                isDarkMode ? 'border-gray-700' : 'border-gray-300'
              }`}
            >
              {(['all', 'recurring', 'non_recurring'] as const).map((key, index) => (
                <button
                  key={key}
                  type="button"
                  aria-pressed={filter === key}
                  onClick={() => setFilter(key)}
                  className={`px-2.5 py-1 text-[11px] font-semibold transition-colors ${
                    index > 0
                      ? isDarkMode
                        ? 'border-l border-gray-700'
                        : 'border-l border-gray-300'
                      : ''
                  } ${
                    filter === key
                      ? isDarkMode
                        ? 'bg-gray-200 text-gray-900'
                        : 'bg-gray-700 text-white'
                      : isDarkMode
                      ? 'bg-gray-900 text-gray-300 hover:bg-gray-800'
                      : 'bg-white text-gray-700 hover:bg-gray-50'
                  }`}
                >
                  {key === 'all' ? 'All' : RECURRENCE_LABEL[key]}
                </button>
              ))}
            </div>
            {actions}
          </div>
        }
      />

      {/* ── Settlement summary ───────────────────────────────────────── */}
      {totals && (
        <div
          className={`grid grid-cols-2 lg:grid-cols-4 gap-px border-b ${
            isDarkMode ? 'bg-gray-800 border-gray-800' : 'bg-gray-200 border-gray-200'
          }`}
        >
          {[
            { label: 'Outstanding', value: totals.unpaid.amount, count: totals.unpaid.count, tone: 'text-red-500 dark:text-red-400' },
            { label: 'Settled', value: totals.paid.amount, count: totals.paid.count, tone: 'text-emerald-600 dark:text-emerald-400' },
            { label: 'Recurring', value: totals.recurring.amount, count: totals.recurring.count, tone: '' },
            { label: 'One-off', value: totals.non_recurring.amount, count: totals.non_recurring.count, tone: '' },
          ].map((tile) => (
            <div key={tile.label} className={`px-4 py-3 ${isDarkMode ? 'bg-gray-900' : 'bg-white'}`}>
              <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                {tile.label}
              </p>
              <p className={`text-lg font-bold tabular-nums ${tile.tone}`}>
                {formatMoney(tile.value)}
              </p>
              <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                {pluralise(tile.count, 'item')}
              </p>
            </div>
          ))}
        </div>
      )}

      {failure && (
        <div className="px-4 py-2 text-sm text-red-500 flex items-center gap-2 border-b border-red-500/20 bg-red-500/5">
          <AlertTriangle size={14} className="flex-shrink-0" />
          {failure}
        </div>
      )}

      <Table>
        <Thead>
          <Th>Expense</Th>
          <Th>Kind</Th>
          <Th>Last booked</Th>
          <Th align="right">Amount</Th>
          <Th align="right" width="150px">
            {ledger ? ledger.month_label : 'Status'}
          </Th>
        </Thead>
        <tbody>
          <TableState
            colSpan={5}
            loading={loading && rows.length === 0}
            error={error}
            empty={visible.length === 0}
            emptyMessage={
              filter === 'all'
                ? 'No expenses were booked in this range.'
                : `No ${RECURRENCE_LABEL[filter as Recurrence].toLowerCase()} expenses in this range.`
            }
          />

          {visible.map((row) => {
            const key = `${row.source ?? ledger?.source ?? ''}:${row.ref}`;
            const busy = pending === key;

            return (
              <Tr key={key}>
                <Td>
                  <span className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                    {row.label}
                  </span>
                  <span
                    className={`block text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}
                  >
                    {pluralise(row.count, 'entry', 'entries')}
                    {row.source_label && <> · {row.source_label}</>}
                    {row.variance !== null && row.variance !== 0 && (
                      <>
                        {' '}
                        ·{' '}
                        <span className="text-amber-500">
                          {/* Surfaced, never reconciled: which side is right is
                              not a question this portal can settle. */}
                          settled {formatMoney(row.paid_amount)} ({row.variance > 0 ? '+' : ''}
                          {formatMoney(row.variance)})
                        </span>
                      </>
                    )}
                  </span>
                </Td>
                <Td>
                  <span className="flex flex-wrap items-center gap-1">
                    <Pill tone={row.recurrence === 'recurring' ? 'info' : 'neutral'}>
                      {RECURRENCE_LABEL[row.recurrence]}
                    </Pill>
                    <Pill tone={row.nature === 'capex' ? 'warning' : 'neutral'}>
                      {row.nature === 'capex' ? 'CapEx' : 'OpEx'}
                    </Pill>
                  </span>
                </Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {formatDate(row.last_booked_at)}
                </Td>
                <Td align="right" className="font-semibold tabular-nums">
                  {formatMoney(row.amount)}
                </Td>
                <Td align="right">
                  {editable ? (
                    <button
                      type="button"
                      onClick={() => toggle(row)}
                      disabled={busy}
                      title={row.is_paid ? 'Mark as unpaid' : 'Mark as paid'}
                      className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold transition-colors disabled:opacity-50 ${
                        row.is_paid
                          ? 'border-emerald-500/40 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/10'
                          : isDarkMode
                          ? 'border-gray-700 text-gray-300 hover:bg-gray-800'
                          : 'border-gray-300 text-gray-600 hover:bg-gray-50'
                      }`}
                    >
                      {busy ? (
                        <Loader2 size={13} className="animate-spin" />
                      ) : row.is_paid ? (
                        <CheckCircle2 size={13} />
                      ) : (
                        <Circle size={13} />
                      )}
                      {row.is_paid ? 'Paid' : 'Unpaid'}
                    </button>
                  ) : (
                    <Pill tone={row.is_paid ? 'success' : 'warning'}>
                      {row.is_paid ? 'Paid' : 'Unpaid'}
                    </Pill>
                  )}

                  {row.is_paid && row.paid_on && (
                    <span
                      className={`block text-[11px] mt-0.5 ${
                        isDarkMode ? 'text-gray-500' : 'text-gray-400'
                      }`}
                    >
                      {formatDate(row.paid_on)}
                      {row.updated_by && <> · {row.updated_by}</>}
                    </span>
                  )}
                </Td>
              </Tr>
            );
          })}

          {visible.length > 0 && (
            <TotalRow>
              <Td>Total</Td>
              <Td />
              <Td />
              <Td align="right" className="tabular-nums">
                {formatMoney(visible.reduce((sum, row) => sum + row.amount, 0))}
              </Td>
              <Td align="right" className="text-red-500 dark:text-red-400 tabular-nums">
                {formatMoney(
                  visible.filter((row) => !row.is_paid).reduce((sum, row) => sum + row.amount, 0)
                )}{' '}
                due
              </Td>
            </TotalRow>
          )}
        </tbody>
      </Table>

      <p
        className={`px-4 py-3 text-xs flex items-start gap-2 border-t ${
          isDarkMode ? 'border-gray-800 text-gray-500' : 'border-gray-200 text-gray-500'
        }`}
      >
        {editable ? <RefreshCw size={13} className="mt-0.5 flex-shrink-0" /> : <Info size={13} className="mt-0.5 flex-shrink-0" />}
        <span>
          Expense lines stay in sync with the operating system and are grouped by obligation.
          The paid/unpaid state is recorded here in MONITOR, per month — this portal never writes
          to a monitored database. Where a settled figure differs from what the source booked, both
          are shown.
        </span>
      </p>
    </Card>
  );
};

export default PayablesPanel;
