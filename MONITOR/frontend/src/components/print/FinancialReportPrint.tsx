import React from 'react';
import { PrintableData } from '../../types/reporting';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '../../utils/format';

interface FinancialReportPrintProps {
  data: PrintableData;
  /** Name and role printed above the "Prepared by" rule. */
  preparedBy: string;
  preparedByRole: string;
}

/**
 * The A4 Financial Report — the document that gets signed and filed.
 *
 * Monospaced and deliberately plain, reproducing the source system's layout: a
 * centred letterhead, the income ledger, the expense ledger, a summary block, a
 * bulleted report summary and two signature rules.
 *
 * The typeface is not a stylistic choice. Courier's fixed advance width makes
 * columns of figures line up digit-for-digit when the report is read against a
 * printed receipt, which is exactly what it is used for.
 */
const FinancialReportPrint: React.FC<FinancialReportPrintProps> = ({
  data,
  preparedBy,
  preparedByRole,
}) => {
  const { company, totals } = data;
  const netPositive = totals.net >= 0;

  return (
    <div style={page}>
      {/* ── Letterhead ──────────────────────────────────────────────── */}
      <div style={header}>
        {company.logo && (
          <img
            src={company.logo}
            alt=""
            style={{ height: '52px', objectFit: 'contain', display: 'block', margin: '0 auto 6px' }}
            onError={(event) => {
              event.currentTarget.style.display = 'none';
            }}
          />
        )}
        <div style={{ fontSize: '17px', fontWeight: 700, letterSpacing: '0.02em' }}>{company.name}</div>
        {company.address && (
          <div style={{ fontSize: '9.5px', color: '#555', marginTop: '2px' }}>{company.address}</div>
        )}
        <div style={reportTitle}>Financial Report</div>
        <div style={{ fontSize: '10.5px', color: '#555', fontWeight: 700 }}>{data.range_label}</div>
      </div>

      {/* ── Income ──────────────────────────────────────────────────── */}
      <h3 style={sectionTitle}>Income</h3>
      <h4 style={subTitle}>Subscriber Payments</h4>

      <table style={table}>
        <thead>
          <tr>
            <th style={th}>OR #</th>
            <th style={th}>Account #</th>
            <th style={th}>Subscriber</th>
            <th style={th}>Type</th>
            <th style={th}>Method</th>
            <th style={{ ...th, textAlign: 'right' }}>Amount ({company.currency_symbol})</th>
          </tr>
        </thead>
        <tbody>
          {data.payments.length === 0 ? (
            <tr>
              <td colSpan={6} style={emptyCell}>
                No payments for this period.
              </td>
            </tr>
          ) : (
            data.payments.map((payment, index) => (
              <tr key={`${payment.or_number}-${index}`}>
                <td style={td}>{payment.or_number || '—'}</td>
                <td style={td}>{payment.account_number || '—'}</td>
                <td style={td}>{payment.subscriber || '—'}</td>
                <td style={td}>{payment.type}</td>
                <td style={td}>{payment.method || '—'}</td>
                <td style={{ ...td, textAlign: 'right', color: '#16a34a', fontWeight: 700 }}>
                  {formatMoney(payment.amount)}
                </td>
              </tr>
            ))
          )}
        </tbody>
        {data.payments.length > 0 && (
          <tfoot>
            <tr style={{ background: '#e8f4fd', fontWeight: 700 }}>
              <td style={td} colSpan={5}>
                Subtotal — {formatNumber(data.payments.length)} payment
                {data.payments.length === 1 ? '' : 's'}
              </td>
              <td style={{ ...td, textAlign: 'right', color: '#16a34a' }}>{formatMoney(totals.income)}</td>
            </tr>
          </tfoot>
        )}
      </table>

      {/* ── Expenses ────────────────────────────────────────────────── */}
      <h3 style={sectionTitle}>Expenses — Employee / Payee</h3>

      <table style={table}>
        <thead>
          <tr>
            <th style={th}>Date</th>
            <th style={th}>Type</th>
            <th style={th}>Employee / Payee</th>
            <th style={th}>Remark</th>
            <th style={{ ...th, textAlign: 'right' }}>Amount ({company.currency_symbol})</th>
          </tr>
        </thead>
        <tbody>
          {data.expenses.length === 0 ? (
            <tr>
              <td colSpan={5} style={emptyCell}>
                No expenses for this period.
              </td>
            </tr>
          ) : (
            data.expenses.map((expense, index) => (
              <tr key={index}>
                <td style={td}>{formatDate(expense.expense_date)}</td>
                <td style={td}>{expense.type}</td>
                <td style={td}>{expense.employee || '—'}</td>
                <td style={td}>{expense.remark || '—'}</td>
                <td style={{ ...td, textAlign: 'right', color: '#dc2626', fontWeight: 700 }}>
                  {formatMoney(expense.amount)}
                </td>
              </tr>
            ))
          )}
        </tbody>
        {data.expenses.length > 0 && (
          <tfoot>
            <tr style={{ background: '#e8f4fd', fontWeight: 700 }}>
              <td style={td} colSpan={4}>
                Subtotal — {formatNumber(data.expenses.length)} record
                {data.expenses.length === 1 ? '' : 's'}
              </td>
              <td style={{ ...td, textAlign: 'right', color: '#dc2626' }}>{formatMoney(totals.expenses)}</td>
            </tr>
          </tfoot>
        )}
      </table>

      {/* A "Payment Notes" table stood here and has been removed along with the
          panel of the same name on the Financial page. It grouped collections by
          the free-text remark a cashier typed, which produced one row per
          spelling of the same note and totalled nothing anybody reconciles
          against. `payment_notes` is still in the API payload — the drivers
          compute it and removing the field would break a published response
          shape for a cosmetic gain — it is simply no longer rendered. */}

      {/* ── Summary ─────────────────────────────────────────────────── */}
      <h3 style={sectionTitle}>Financial Summary</h3>

      <table style={{ ...table, marginBottom: '14px' }}>
        <tbody>
          <tr>
            <td style={{ ...td, fontWeight: 700 }}>Total Income</td>
            <td style={{ ...td, textAlign: 'right', color: '#16a34a', fontWeight: 700 }}>
              {formatMoney(totals.income)}
            </td>
          </tr>
          <tr>
            <td style={{ ...td, fontWeight: 700 }}>Total Expenses</td>
            <td style={{ ...td, textAlign: 'right', color: '#dc2626', fontWeight: 700 }}>
              {formatMoney(totals.expenses)}
            </td>
          </tr>
          <tr style={{ background: '#f0f0f0' }}>
            <td style={{ ...td, fontWeight: 700, letterSpacing: '0.05em', textTransform: 'uppercase' }}>
              {netPositive ? 'Net Profit' : 'Net Loss'}
            </td>
            <td
              style={{
                ...td,
                textAlign: 'right',
                fontWeight: 700,
                color: netPositive ? '#1d4ed8' : '#dc2626',
              }}
            >
              {formatMoney(totals.net)}
            </td>
          </tr>
        </tbody>
      </table>

      <div style={summaryBox} className="print-keep">
        <div style={{ fontWeight: 700, marginBottom: '4px' }}>Report Summary</div>
        <ul style={{ margin: 0, paddingLeft: '16px' }}>
          <li>Report Period: {data.range_label}</li>
          <li>Total Payments Collected: {formatNumber(totals.income_count)}</li>
          <li>Total Expense Records: {formatNumber(totals.expenses_count)}</li>
          <li>Router / Branch: {data.branch_label}</li>
          {/* Expense scope is stated because it changes the net: a three-day
              range excludes monthly bookings that a full month would include. */}
          <li>Expense Scope: {data.expense_period} bookings and shorter</li>
          <li>Generated: {formatDateTime(data.generated_at)}</li>
        </ul>
      </div>

      {/* ── Signatures ──────────────────────────────────────────────── */}
      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: '44px', fontSize: '10.5px' }} className="print-keep">
        <div style={{ width: '45%' }}>
          <div>Prepared by:</div>
          <div style={signatureRule}>
            <div style={{ fontWeight: 700, textTransform: 'uppercase' }}>{preparedBy || ' '}</div>
            <div style={{ fontSize: '9px', color: '#555' }}>{preparedByRole}</div>
          </div>
        </div>
        <div style={{ width: '45%' }}>
          <div style={{ textAlign: 'right' }}>Noted by:</div>
          <div style={signatureRule}>
            <div style={{ fontWeight: 700, textTransform: 'uppercase' }}>
              {company.manager || ' '}
            </div>
            <div style={{ fontSize: '9px', color: '#555' }}>
              Authorized Signatory / Branch Manager
            </div>
          </div>
        </div>
      </div>

      <div style={{ marginTop: '28px', borderTop: '1px solid #ddd', paddingTop: '4px', fontSize: '9px', color: '#555', textAlign: 'center' }}>
        {company.name} — For Internal Use Only — Confidential
      </div>
    </div>
  );
};

// Inline styles throughout: printed output must not depend on a stylesheet the
// browser may not have applied, and this palette is the document's, not the app's.
const page: React.CSSProperties = {
  fontFamily: "'Courier New', Courier, monospace",
  fontSize: '11px',
  color: '#1a1a1a',
  background: '#fff',
  lineHeight: 1.45,
  maxWidth: '780px',
  margin: '0 auto',
};

const header: React.CSSProperties = {
  textAlign: 'center',
  marginBottom: '14px',
  paddingBottom: '8px',
  borderBottom: '2px solid #1a1a1a',
};

const reportTitle: React.CSSProperties = {
  fontSize: '14px',
  fontWeight: 700,
  textTransform: 'uppercase',
  letterSpacing: '0.08em',
  margin: '8px 0 2px',
};

const sectionTitle: React.CSSProperties = {
  fontSize: '11px',
  fontWeight: 700,
  textTransform: 'uppercase',
  letterSpacing: '0.04em',
  color: '#666',
  margin: '18px 0 8px',
};

const subTitle: React.CSSProperties = {
  fontSize: '10px',
  fontWeight: 700,
  textTransform: 'uppercase',
  letterSpacing: '0.04em',
  color: '#666',
  margin: '10px 0 5px',
};

const table: React.CSSProperties = {
  width: '100%',
  borderCollapse: 'collapse',
  marginBottom: '10px',
  fontSize: '10px',
};

const th: React.CSSProperties = {
  padding: '5px 7px',
  border: '1px solid #ccc',
  textAlign: 'left',
  fontSize: '8.5px',
  fontWeight: 700,
  textTransform: 'uppercase',
  letterSpacing: '0.04em',
  color: '#666',
  whiteSpace: 'nowrap',
};

const td: React.CSSProperties = {
  padding: '4px 7px',
  border: '1px solid #ddd',
  verticalAlign: 'middle',
};

const emptyCell: React.CSSProperties = {
  padding: '10px 7px',
  border: '1px solid #ddd',
  textAlign: 'center',
  fontStyle: 'italic',
  color: '#777',
};

const summaryBox: React.CSSProperties = {
  padding: '10px 14px',
  background: '#f8f8f8',
  border: '1px solid #ccc',
  fontSize: '10px',
  lineHeight: 1.7,
};

const signatureRule: React.CSSProperties = {
  borderTop: '1px solid #1a1a1a',
  marginTop: '34px',
  paddingTop: '4px',
  textAlign: 'center',
};

export default FinancialReportPrint;
