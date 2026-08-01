import React from 'react';
import { useTheme } from '../../hooks/useTheme';
import { formatMoney, formatNumber } from '../../utils/format';
import Card, { CardHeader } from './Card';
import { Table, TableState, Td, Th, Thead, TotalRow, Tr } from './primitives';

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
}

/**
 * Label / count / amount, sorted by amount.
 *
 * One component behind Revenue by Plan, Revenue by Payment Method and Payment
 * Notes: three panels that are the same table with different column headings, and
 * keeping them as one is what stops them diverging.
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
}) => {
  const isDarkMode = useTheme();

  const columns = hideCount ? 2 : 3;
  const total = rows.reduce((sum, row) => sum + row.total, 0);

  return (
    <Card flush className="h-full">
      <CardHeader title={title} />

      <Table>
        <Thead>
          <Th>{labelHeader}</Th>
          {!hideCount && <Th align="right">{countLabel}</Th>}
          <Th align="right">{totalLabel}</Th>
        </Thead>
        <tbody>
          <TableState
            colSpan={columns}
            loading={loading && rows.length === 0}
            error={error}
            empty={rows.length === 0}
            emptyMessage={emptyMessage}
          />

          {rows.map((row) => (
            <Tr key={row.label}>
              <Td>
                <span className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                  {row.label}
                </span>
                {row.detail && (
                  <span className={`block text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                    {row.detail}
                  </span>
                )}
              </Td>
              {!hideCount && (
                <Td align="right" className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {formatNumber(row.count)}
                </Td>
              )}
              <Td align="right" className="font-semibold text-emerald-600 dark:text-emerald-400">
                {formatMoney(row.total)}
              </Td>
            </Tr>
          ))}

          {showTotal && rows.length > 0 && (
            <TotalRow>
              <Td>Total</Td>
              {!hideCount && (
                <Td align="right">{formatNumber(rows.reduce((sum, row) => sum + row.count, 0))}</Td>
              )}
              <Td align="right" className="text-emerald-600 dark:text-emerald-400">
                {formatMoney(total)}
              </Td>
            </TotalRow>
          )}
        </tbody>
      </Table>
    </Card>
  );
};

export default BreakdownTable;
