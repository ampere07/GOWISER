import React from 'react';
import { useTheme } from '../../hooks/useTheme';
import { formatNumber } from '../../utils/format';
import { BarangayRow } from '../../types/reporting';
import Card, { CardHeader } from './Card';
import { Bar, RankBadge, Table, TableState, Td, Th, Thead, Tr } from './primitives';

interface TopBarangaysPanelProps {
  rows: BarangayRow[];
  loading: boolean;
  error: string | null;
}

/**
 * The ten barangays with the most subscribers, with their active/expired split.
 *
 * Bars are scaled against the largest row rather than the total: this is a
 * ranking, and the useful comparison is "how does number four compare with
 * number one", not "what share of everything is it".
 */
const TopBarangaysPanel: React.FC<TopBarangaysPanelProps> = ({ rows, loading, error }) => {
  const isDarkMode = useTheme();

  const largest = rows.reduce((max, row) => Math.max(max, row.total), 0);

  return (
    <Card flush>
      <CardHeader title="Top 10 Barangays by Subscribers" />

      <Table>
        <Thead>
          <Th width="60px">#</Th>
          <Th>Barangay</Th>
          <Th>Municipality</Th>
          <Th align="right" className="text-emerald-600 dark:text-emerald-400">
            Act.
          </Th>
          <Th align="right" className="text-red-500 dark:text-red-400">
            Exp.
          </Th>
          <Th align="right">Total</Th>
          <Th width="90px" />
        </Thead>
        <tbody>
          <TableState
            colSpan={7}
            loading={loading && rows.length === 0}
            error={error}
            empty={rows.length === 0}
            emptyMessage="No subscribers have a barangay recorded."
          />

          {rows.map((row, index) => (
            <Tr key={`${row.barangay}-${row.municipality}-${row.province}`}>
              <Td>
                <RankBadge rank={index + 1} />
              </Td>
              <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {row.barangay}
              </Td>
              <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {row.municipality || '—'}
              </Td>
              <Td align="right" className="text-emerald-600 dark:text-emerald-400">
                {formatNumber(row.active)}
              </Td>
              <Td align="right" className="text-red-500 dark:text-red-400">
                {formatNumber(row.expired)}
              </Td>
              <Td align="right" className={`font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {formatNumber(row.total)}
              </Td>
              <Td>
                <Bar pct={largest > 0 ? (row.total / largest) * 100 : 0} />
              </Td>
            </Tr>
          ))}
        </tbody>
      </Table>
    </Card>
  );
};

export default TopBarangaysPanel;
