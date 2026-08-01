import React from 'react';
import { PrintCompany } from '../../types/reporting';
import { formatDateTime, formatMoney, formatNumber } from '../../utils/format';

/**
 * The banded report layout shared by the Payment Report and the Expense Report.
 *
 * Both are the same document in two liveries — a full-bleed coloured header band,
 * a summary strip, a coloured table header, a page subtotal row and a
 * confidentiality footer. The only differences are the accent colour, the title
 * and the columns, so they are one component parameterised by those rather than
 * two near-identical files that drift.
 *
 * Rendered with inline styles, not Tailwind classes. These layouts are printed:
 * they must survive a browser that ignores stylesheets it did not load, and their
 * palette is fixed by the document design rather than the app theme.
 */

export interface BandedColumn<T> {
  header: string;
  align?: 'left' | 'right' | 'center';
  width?: string;
  render: (row: T, index: number) => React.ReactNode;
}

export interface BandedSummary {
  label: string;
  value: string;
  /** Renders in the accent colour — used for the money figure. */
  accent?: boolean;
}

interface BandedReportProps<T> {
  /** Header band and table header colour. */
  accent: string;
  /** Tint behind the summary strip and the subtotal row. */
  tint: string;
  title: string;
  /** Line under the title: the period, the collection type. */
  subtitle: string;
  /** Extra line in the right-hand block, e.g. the branch. */
  scope?: string;
  company: PrintCompany;
  generatedAt: string;
  summary: BandedSummary[];
  /** Pills describing the filters that produced this report. */
  filters?: string[];
  columns: BandedColumn<T>[];
  rows: T[];
  /** Total of the money column, shown on the subtotal row. */
  total: number;
  emptyMessage: string;
}

function BandedReport<T>({
  accent,
  tint,
  title,
  subtitle,
  scope,
  company,
  generatedAt,
  summary,
  filters,
  columns,
  rows,
  total,
  emptyMessage,
}: BandedReportProps<T>) {
  return (
    <div
      style={{
        fontFamily: "'Segoe UI', 'Helvetica Neue', Arial, sans-serif",
        fontSize: '11px',
        color: '#1a1a1a',
        background: '#fff',
        width: '100%',
      }}
    >
      {/* ── Header band ─────────────────────────────────────────────── */}
      <div style={{ display: 'flex', alignItems: 'stretch', background: accent, color: '#fff' }}>
        <div style={{ flex: '1 1 auto', display: 'flex', alignItems: 'center', gap: '14px', padding: '12px 16px', minWidth: 0 }}>
          {company.logo && (
            <img
              src={company.logo}
              alt=""
              style={{ height: '46px', width: 'auto', objectFit: 'contain', flexShrink: 0 }}
              // A broken logo URL must not leave a browser's placeholder icon in
              // the middle of a signed document.
              onError={(event) => {
                event.currentTarget.style.display = 'none';
              }}
            />
          )}
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: '19px', fontWeight: 700, lineHeight: 1.15 }}>{company.name}</div>
            <div style={{ fontSize: '10px', opacity: 0.85 }}>{company.description}</div>
          </div>
        </div>

        <div
          style={{
            flex: '0 0 auto',
            padding: '12px 20px',
            textAlign: 'center',
            borderLeft: '1px solid rgba(255,255,255,0.28)',
          }}
        >
          <div style={{ fontSize: '16px', fontWeight: 700, letterSpacing: '0.04em' }}>{title}</div>
          <div style={{ fontSize: '10px', opacity: 0.9, marginTop: '2px' }}>{subtitle}</div>
        </div>

        <div
          style={{
            flex: '0 0 auto',
            padding: '12px 16px',
            textAlign: 'right',
            borderLeft: '1px solid rgba(255,255,255,0.28)',
          }}
        >
          <div style={{ fontSize: '11px', fontWeight: 700 }}>Printed</div>
          <div style={{ fontSize: '10px', opacity: 0.9 }}>{formatDateTime(generatedAt)}</div>
          {scope && <div style={{ fontSize: '10px', opacity: 0.9 }}>{scope}</div>}
        </div>
      </div>

      {/* ── Summary strip ───────────────────────────────────────────── */}
      <div
        style={{
          display: 'flex',
          alignItems: 'stretch',
          background: tint,
          borderBottom: `2px solid ${accent}`,
        }}
      >
        {summary.map((item) => (
          <div
            key={item.label}
            style={{
              padding: '8px 16px',
              borderRight: `1px solid ${accent}33`,
              flex: '0 0 auto',
              minWidth: '96px',
            }}
          >
            <div style={{ fontSize: '9px', letterSpacing: '0.06em', color: accent, textTransform: 'uppercase' }}>
              {item.label}
            </div>
            <div
              style={{
                fontSize: '13px',
                fontWeight: 700,
                color: item.accent ? accent : '#1a1a1a',
              }}
            >
              {item.value}
            </div>
          </div>
        ))}

        <div style={{ flex: '1 1 auto' }} />

        {filters && filters.length > 0 && (
          <div style={{ padding: '8px 16px', textAlign: 'right' }}>
            <div style={{ fontSize: '9px', letterSpacing: '0.06em', color: accent, textTransform: 'uppercase' }}>
              Active Filters
            </div>
            <div style={{ display: 'flex', gap: '6px', justifyContent: 'flex-end', flexWrap: 'wrap', marginTop: '3px' }}>
              {filters.map((filter) => (
                <span
                  key={filter}
                  style={{
                    fontSize: '9px',
                    padding: '2px 7px',
                    borderRadius: '9px',
                    border: `1px solid ${accent}55`,
                    color: accent,
                    background: '#fff',
                    whiteSpace: 'nowrap',
                  }}
                >
                  {filter}
                </span>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* ── Ledger ──────────────────────────────────────────────────── */}
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead>
          <tr style={{ background: accent, color: '#fff' }}>
            <th style={{ ...headerCell, width: '32px', textAlign: 'left' }}>#</th>
            {columns.map((column) => (
              <th
                key={column.header}
                style={{ ...headerCell, textAlign: column.align ?? 'left', width: column.width }}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td
                colSpan={columns.length + 1}
                style={{ padding: '28px 12px', textAlign: 'center', color: '#6b7280', fontStyle: 'normal' }}
              >
                {emptyMessage}
              </td>
            </tr>
          ) : (
            rows.map((row, index) => (
              <tr
                key={index}
                style={{
                  borderBottom: '1px solid #e5e7eb',
                  // Zebra striping survives a monochrome printer, unlike colour.
                  background: index % 2 === 1 ? '#fafafa' : '#fff',
                }}
              >
                <td style={{ ...bodyCell, color: '#9ca3af' }}>{index + 1}</td>
                {columns.map((column) => (
                  <td key={column.header} style={{ ...bodyCell, textAlign: column.align ?? 'left' }}>
                    {column.render(row, index)}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
        <tfoot>
          <tr style={{ background: tint, borderTop: `2px solid ${accent}` }}>
            <td
              colSpan={Math.max(1, columns.length - 1)}
              style={{
                ...bodyCell,
                fontWeight: 700,
                color: accent,
                letterSpacing: '0.04em',
                textTransform: 'uppercase',
              }}
            >
              Total
            </td>
            <td colSpan={2} style={{ ...bodyCell, textAlign: 'right', fontWeight: 700, color: accent }}>
              {formatMoney(total)}
              <span style={{ color: '#6b7280', fontWeight: 400, marginLeft: '10px' }}>
                {formatNumber(rows.length)} {rows.length === 1 ? 'row' : 'rows'}
              </span>
            </td>
          </tr>
        </tfoot>
      </table>

      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          fontSize: '9px',
          color: '#6b7280',
          borderTop: '1px solid #e5e7eb',
          padding: '6px 2px',
        }}
      >
        <span>{company.name} — For Internal Use Only — Confidential</span>
        {company.tin && <span>TIN: {company.tin}</span>}
      </div>
    </div>
  );
}

const headerCell: React.CSSProperties = {
  padding: '7px 10px',
  fontSize: '9px',
  fontWeight: 600,
  letterSpacing: '0.06em',
  textTransform: 'uppercase',
  whiteSpace: 'nowrap',
};

const bodyCell: React.CSSProperties = {
  padding: '6px 10px',
  fontSize: '10.5px',
  verticalAlign: 'middle',
};

export default BandedReport;
