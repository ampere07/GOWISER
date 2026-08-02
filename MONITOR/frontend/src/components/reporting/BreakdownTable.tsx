import React from 'react';
import { useTheme } from '../../hooks/useTheme';
import { formatMoney, formatNumber } from '../../utils/format';
import DataTable, { Column } from './DataTable';
import { Td, TotalRow } from './primitives';

export interface BreakdownRow {
  label: string;
  count: number;
  total: number;
  /** Second line under the label — a subscriber count, a note author. */
  detail?: string;
}

interface BreakdownTableProps {
  title: string;
  /** Header for the first column: "Plan", "Method", "Note". */
  labelHeader: string;
  /** Header for the count column: "Payments", "Subscribers", "Entries". */
  countLabel: string;
  /** Header for the money column: "Revenue", "Amount". */
  totalLabel: string;
  rows: BreakdownRow[];
  loading: boolean;
  error: string | null;
  emptyMessage?: string;
  showTotal?: boolean;
  /** Hides the count column for breakdowns where it means nothing. */
  hideCount?: boolean;
  /** Rendered in the header beside the search box — usually a WidgetRange. */
  actions?: React.ReactNode;
}

/**
 * Label / count / amount, searchable and sortable on every column.
 *
 * One component behind Revenue by Plan, Revenue by Payment Method and Payment
 * Notes: three panels that are the same table with different column headings,
 * and keeping them as one is what stops them diverging.
 *
 * Sorting is on the underlying numbers rather than the rendered strings, so the
 * amount column orders by value and not by the text of "₱1,234.00" — which would
 * put ₱9 above ₱1,000.
 */
const BreakdownTable: React.FC<BreakdownTableProps> = ({
  title,
  labelHeader,
  countLabel,
  totalLabel,
  rows,
  loading,
  error,
  emptyMessage = 'No data for this period.',
  showTotal = false,
  hideCount = false,
  actions,
}) => {
  const isDarkMode = useTheme();

  const columns: Column<BreakdownRow>[] = [
    {
      key: 'label',
      header: labelHeader,
      value: (row) => row.label,
      render: (row) => (
        <>
          <span className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
            {row.label}
          </span>
          {row.detail && (
            <span
              className={`block text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}
            >
              {row.detail}
            </span>
          )}
        </>
      ),
    },
    ...(hideCount
      ? []
      : [
          {
            key: 'count',
            header: countLabel,
            align: 'right' as const,
            value: (row: BreakdownRow) => row.count,
            // A count is not something anyone searches for by typing it.
            searchable: false,
            render: (row: BreakdownRow) => (
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {formatNumber(row.count)}
              </span>
            ),
          },
        ]),
    {
      key: 'total',
      header: totalLabel,
      align: 'right',
      value: (row) => row.total,
      searchable: false,
      render: (row) => (
        <span className="font-semibold text-emerald-600 dark:text-emerald-400">
          {formatMoney(row.total)}
        </span>
      ),
    },
  ];

  return (
    <DataTable<BreakdownRow>
      title={title}
      columns={columns}
      rows={rows}
      rowKey={(row) => row.label}
      loading={loading}
      error={error}
      emptyMessage={emptyMessage}
      defaultSort="total"
      searchPlaceholder={`Search ${labelHeader.toLowerCase()}…`}
      actions={actions}
      footer={
        showTotal
          ? (visible) => (
              <TotalRow>
                <Td>Total</Td>
                {!hideCount && (
                  <Td align="right">
                    {formatNumber(visible.reduce((sum, row) => sum + row.count, 0))}
                  </Td>
                )}
                {/* Totals the *visible* rows, so a filtered subtotal adds up to
                    what is on screen rather than to a set that is not. */}
                <Td align="right" className="text-emerald-600 dark:text-emerald-400">
                  {formatMoney(visible.reduce((sum, row) => sum + row.total, 0))}
                </Td>
              </TotalRow>
            )
          : undefined
      }
    />
  );
};

export default BreakdownTable;
