import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertTriangle, Loader2, Printer, X } from 'lucide-react';
import BandedReport, { BandedColumn } from './BandedReport';
import FinancialReportPrint from './FinancialReportPrint';
import { reportingService } from '../../services/reportingService';
import {
  PrintExpenseLine,
  PrintLayout,
  PrintPaymentLine,
  PrintableData,
} from '../../types/reporting';
import { formatDate, formatMoney, formatNumber } from '../../utils/format';

/** Liveries from the reference: navy for collections, maroon for spending. */
const PAYMENT_ACCENT = '#123763';
const PAYMENT_TINT = '#eef3fa';
const EXPENSE_ACCENT = '#7b1a1a';
const EXPENSE_TINT = '#fdeeee';

const LAYOUTS: { key: PrintLayout; label: string }[] = [
  { key: 'financial', label: 'Financial Report' },
  { key: 'payments', label: 'Payment Report' },
  { key: 'expenses', label: 'Expense Report' },
];

interface PrintReportOverlayProps {
  open: boolean;
  onClose: () => void;
  source: string;
  dateFrom: string;
  dateTo: string;
  branch: string | null;
  /** Which layout to open on. The user can switch once it is up. */
  initialLayout?: PrintLayout;
  /** Printed above the "Prepared by" rule on the Financial Report. */
  preparedBy: string;
  preparedByRole: string;
}

/**
 * Print preview for the three report layouts.
 *
 * Deliberately a preview rather than an immediate window.print(): the range, the
 * branch and the layout all change what comes out of the printer, and these are
 * multi-page documents on a thermal-or-A4 office printer. Letting someone check
 * before committing paper is worth one extra click.
 *
 * Portalled to <body> so the print stylesheet in index.css can hide the app
 * around it — see the comment there for why hiding siblings is the only reliable
 * approach.
 */
const PrintReportOverlay: React.FC<PrintReportOverlayProps> = ({
  open,
  onClose,
  source,
  dateFrom,
  dateTo,
  branch,
  initialLayout = 'financial',
  preparedBy,
  preparedByRole,
}) => {
  const [layout, setLayout] = useState<PrintLayout>(initialLayout);
  const [data, setData] = useState<PrintableData | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /**
   * The range this report covers, editable here.
   *
   * Seeded from the caller and then owned by the overlay, which is a change
   * from taking the page's range as gospel. A printed financial report is
   * almost always a month or a quarter, and the page behind it now opens on
   * Daily — so inheriting it produced a preview covering today, which on a
   * quiet morning is a correctly-built report with nothing in it. That is
   * indistinguishable, at a glance, from a broken one.
   *
   * Editable rather than merely widened to a month, because the alternative is
   * closing the preview, changing a widget range on the page behind it, and
   * reopening — to change the one thing this dialog exists to produce.
   */
  const [from, setFrom] = useState(dateFrom);
  const [to, setTo] = useState(dateTo);

  // Re-seeded on open rather than on every prop change, so adjusting the dates
  // in here is not undone by a widget moving on the page underneath.
  useEffect(() => {
    if (!open) return;

    setLayout(initialLayout);
    setFrom(dateFrom);
    setTo(dateTo);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  /**
   * Marks the body while the overlay is up, which is what arms the print rules.
   * Scroll is locked too: the page behind is hidden for print but still
   * scrollable on screen, and a stray wheel event moving it is disorienting.
   */
  useEffect(() => {
    if (!open) return;

    document.body.classList.add('monitor-printing');
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };

    window.addEventListener('keydown', onKeyDown);

    return () => {
      document.body.classList.remove('monitor-printing');
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [open, onClose]);

  // Fetched fresh every time the overlay opens or the range changes. A printed
  // report is a record someone signs, so it must reflect the ledger now — not a
  // cached copy from whenever the page was last loaded.
  useEffect(() => {
    if (!open || !source) return;

    let cancelled = false;
    setLoading(true);
    setError(null);

    reportingService
      .getPrintable(source, from, to, branch)
      .then((result) => {
        if (cancelled) return;
        setData(result.data);
      })
      .catch((err) => {
        if (cancelled) return;

        console.error('Printable report fetch failed:', err);
        setError(
          err?.response?.data?.message ||
            'Unable to build this report. The source database may be unreachable.'
        );
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [open, source, from, to, branch]);

  if (!open) return null;

  const filters = data
    ? [`From: ${formatDate(data.range.from)}`, `To: ${formatDate(data.range.to)}`].concat(
        data.branch_label !== 'All branches' ? [data.branch_label] : []
      )
    : [];

  return createPortal(
    <div
      className="monitor-print-portal fixed inset-0 z-[10050] overflow-y-auto bg-white"
      role="dialog"
      aria-modal="true"
      aria-label="Print preview"
    >
      {/* Screen-only toolbar. `no-print` is stripped by the print stylesheet. */}
      <div className="no-print sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3">
        <div className="inline-flex rounded-lg border border-gray-300 overflow-hidden">
          {LAYOUTS.map((option, index) => (
            <button
              key={option.key}
              type="button"
              aria-pressed={layout === option.key}
              onClick={() => setLayout(option.key)}
              className={`px-3.5 py-1.5 text-xs font-semibold transition-colors ${
                index > 0 ? 'border-l border-gray-300' : ''
              } ${
                layout === option.key
                  ? 'bg-gray-700 text-white'
                  : 'bg-white text-gray-700 hover:bg-gray-100'
              }`}
            >
              {option.label}
            </button>
          ))}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* The range, editable. A report covering the wrong month is the
              commonest thing to get wrong about a printout, and it is worth
              being able to correct without leaving the preview. */}
          <label className="flex items-center gap-1.5 text-xs text-gray-500">
            <span className="hidden sm:inline">Range</span>
            <input
              type="date"
              value={from}
              max={to || undefined}
              onChange={(event) => setFrom(event.target.value)}
              aria-label="Report start date"
              className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs tabular-nums text-gray-700"
            />
          </label>
          <span className="text-xs text-gray-400">→</span>
          <input
            type="date"
            value={to}
            min={from || undefined}
            onChange={(event) => setTo(event.target.value)}
            aria-label="Report end date"
            className="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs tabular-nums text-gray-700"
          />

          {/* Stated even when the report is empty, so "nothing was collected in
              this range" is legible as an answer rather than as a failure. */}
          <span className="text-xs text-gray-500 hidden lg:inline">
            {data ? data.range_label : `${from} – ${to}`}
          </span>

          <button
            type="button"
            onClick={() => window.print()}
            disabled={loading || !data}
            className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <Printer size={14} />
            Print
          </button>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close print preview"
            className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
          >
            <X size={14} />
          </button>
        </div>
      </div>

      <div className="px-3 py-4 sm:px-6 sm:py-6">
        {loading && !data ? (
          <div className="no-print flex items-center justify-center gap-2 py-24 text-sm text-gray-500">
            <Loader2 size={16} className="animate-spin" />
            Building report…
          </div>
        ) : error ? (
          <div className="no-print mx-auto flex max-w-lg items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertTriangle size={16} className="flex-shrink-0" />
            {error}
          </div>
        ) : data ? (
          <>
            {/* Said out loud rather than left to be inferred from a document
                with nothing in it. An empty report and a broken one look
                identical on screen, and the first thing to check is the range —
                which is now one control away, at the top of this dialog. */}
            {data.payments.length === 0 && data.expenses.length === 0 && (
              <div className="no-print mx-auto mb-4 flex max-w-lg items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <AlertTriangle size={16} className="flex-shrink-0" />
                <span>
                  Nothing was collected or spent in {data.range_label}. The report below is
                  correct and empty — widen the range above if that is not what you meant.
                </span>
              </div>
            )}

            <ReportBody
              layout={layout}
              data={data}
              filters={filters}
              preparedBy={preparedBy}
              preparedByRole={preparedByRole}
            />
          </>
        ) : null}
      </div>
    </div>,
    document.body
  );
};

const ReportBody: React.FC<{
  layout: PrintLayout;
  data: PrintableData;
  filters: string[];
  preparedBy: string;
  preparedByRole: string;
}> = ({ layout, data, filters, preparedBy, preparedByRole }) => {
  if (layout === 'financial') {
    return (
      <FinancialReportPrint data={data} preparedBy={preparedBy} preparedByRole={preparedByRole} />
    );
  }

  if (layout === 'payments') {
    const columns: BandedColumn<PrintPaymentLine>[] = [
      { header: 'OR No.', render: (row) => row.or_number || '—' },
      {
        header: 'Subscriber',
        render: (row) => <span style={{ fontWeight: 600 }}>{row.subscriber || '—'}</span>,
      },
      { header: 'Account #', render: (row) => row.account_number || '—' },
      {
        header: 'Amount',
        align: 'right',
        render: (row) => (
          <span style={{ color: '#16a34a', fontWeight: 700 }}>{formatMoney(row.amount)}</span>
        ),
      },
      { header: 'Type', render: (row) => row.type },
      { header: 'Method', render: (row) => row.method || '—' },
      { header: 'Date', align: 'right', render: (row) => formatDate(row.payment_date) },
      {
        header: 'Status',
        align: 'right',
        render: (row) => <span style={{ textTransform: 'capitalize' }}>{row.status || '—'}</span>,
      },
    ];

    return (
      <BandedReport<PrintPaymentLine>
        accent={PAYMENT_ACCENT}
        tint={PAYMENT_TINT}
        title="Payment Report"
        subtitle={`Collections — ${data.range_label}`}
        scope={data.branch_label !== 'All branches' ? `Router: ${data.branch_label}` : undefined}
        company={data.company}
        generatedAt={data.generated_at}
        summary={[
          { label: 'Total Amount', value: formatMoney(data.totals.income), accent: true },
          { label: 'Total Records', value: formatNumber(data.totals.income_count) },
        ]}
        filters={filters}
        columns={columns}
        rows={data.payments}
        total={data.totals.income}
        emptyMessage="No records found."
      />
    );
  }

  const columns: BandedColumn<PrintExpenseLine>[] = [
    { header: 'Date', render: (row) => formatDate(row.expense_date) },
    { header: 'Type', render: (row) => row.type },
    {
      header: 'Employee / Payee',
      render: (row) => <span style={{ fontWeight: 600 }}>{row.employee || '—'}</span>,
    },
    // Period is shown because it is why a row is in this report at all: a
    // 'monthly' booking appears in a month's report and not in a day's.
    { header: 'Period', render: (row) => <span style={{ textTransform: 'capitalize' }}>{row.period_type}</span> },
    { header: 'Remark', render: (row) => row.remark || '—' },
    {
      header: 'Amount',
      align: 'right',
      render: (row) => (
        <span style={{ color: '#dc2626', fontWeight: 700 }}>{formatMoney(row.amount)}</span>
      ),
    },
  ];

  return (
    <BandedReport<PrintExpenseLine>
      accent={EXPENSE_ACCENT}
      tint={EXPENSE_TINT}
      title="Expense Report"
      subtitle={`Period: ${data.range_label}`}
      scope={data.branch_label !== 'All branches' ? `Router: ${data.branch_label}` : undefined}
      company={data.company}
      generatedAt={data.generated_at}
      summary={[
        { label: 'Total Expenses', value: formatMoney(data.totals.expenses), accent: true },
        { label: 'Total Records', value: formatNumber(data.totals.expenses_count) },
      ]}
      filters={filters}
      columns={columns}
      rows={data.expenses}
      total={data.totals.expenses}
      emptyMessage="No records found."
    />
  );
};

export default PrintReportOverlay;
