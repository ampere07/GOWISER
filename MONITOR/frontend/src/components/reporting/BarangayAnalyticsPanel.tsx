import React, { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, Search } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { formatNumber } from '../../utils/format';
import { BarangayRow } from '../../types/reporting';
import Card, { CardHeader } from './Card';
import { Table, TableState, Td, Th, Thead, Tr } from './primitives';

interface BarangayAnalyticsPanelProps {
  rows: BarangayRow[];
  loading: boolean;
  error: string | null;
}

/** The sortable columns, and how each reads out of a row. */
type SortKey = 'barangay' | 'municipality' | 'active' | 'vip' | 'inactive' | 'pullout' | 'total';

const NUMERIC_KEYS: SortKey[] = ['active', 'vip', 'inactive', 'pullout', 'total'];

/**
 * Barangay Analytics — the whole footprint, not a leaderboard.
 *
 * Replaces the old Top 10 panel. A top-N list answers "where are we biggest", which nobody
 * needed twice; this answers "what does coverage look like in the barangay I am asking about",
 * which needs every row present and a way to order them.
 *
 * Sorting is client-side because the server already collapses the account table down to one row
 * per barangay — a few hundred rows at most, where a round trip per sort would cost more than
 * the sort itself.
 */
const BarangayAnalyticsPanel: React.FC<BarangayAnalyticsPanelProps> = ({ rows, loading, error }) => {
  const isDarkMode = useTheme();
  const [sortKey, setSortKey] = useState<SortKey>('total');
  const [ascending, setAscending] = useState(false);
  const [search, setSearch] = useState('');

  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase();

    const filtered = needle
      ? rows.filter(
          (row) =>
            row.barangay.toLowerCase().includes(needle) ||
            row.municipality.toLowerCase().includes(needle) ||
            row.province.toLowerCase().includes(needle)
        )
      : rows;

    // Copied before sorting: the array belongs to the fetched section data, and sorting it in
    // place would reorder what every other consumer of that response sees.
    return [...filtered].sort((a, b) => {
      if (NUMERIC_KEYS.includes(sortKey)) {
        const diff = Number(a[sortKey]) - Number(b[sortKey]);
        // Ties fall back to the name, so repeated sorts of equal counts stay stable rather than
        // shuffling rows around on every re-render.
        return (diff !== 0 ? diff : a.barangay.localeCompare(b.barangay)) * (ascending ? 1 : -1);
      }

      return String(a[sortKey]).localeCompare(String(b[sortKey])) * (ascending ? 1 : -1);
    });
  }, [rows, search, sortKey, ascending]);

  const toggle = (key: SortKey) => {
    if (key === sortKey) {
      setAscending((prev) => !prev);
      return;
    }
    setSortKey(key);
    // Names read best A→Z, counts best highest-first, so a fresh column starts the way that
    // column is normally read rather than inheriting the previous column's direction.
    setAscending(!NUMERIC_KEYS.includes(key));
  };

  const SortableTh: React.FC<{
    label: string;
    sortBy: SortKey;
    align?: 'left' | 'right';
    className?: string;
    width?: string;
  }> = ({ label, sortBy, align = 'left', className, width }) => (
    <Th align={align} className={className} width={width}>
      <button
        type="button"
        onClick={() => toggle(sortBy)}
        className={`inline-flex items-center gap-1 hover:underline ${
          align === 'right' ? 'flex-row-reverse' : ''
        }`}
        aria-label={`Sort by ${label}`}
      >
        <span>{label}</span>
        {sortKey === sortBy &&
          (ascending ? <ArrowUp size={12} /> : <ArrowDown size={12} />)}
      </button>
    </Th>
  );

  return (
    <Card flush>
      <CardHeader
        title="Barangay Analytics"
        subtitle={`${formatNumber(rows.length)} barangay${rows.length === 1 ? '' : 's'} · sortable`}
        actions={
          <div className="relative">
            <Search
              size={14}
              className={`absolute left-2.5 top-1/2 -translate-y-1/2 ${
                isDarkMode ? 'text-gray-500' : 'text-gray-400'
              }`}
            />
            <input
              type="text"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Find barangay…"
              className={`w-48 rounded-md border py-1 pl-8 pr-2 text-xs focus:outline-none ${
                isDarkMode
                  ? 'border-gray-700 bg-gray-900 text-gray-200 placeholder-gray-600'
                  : 'border-gray-300 bg-white text-gray-800 placeholder-gray-400'
              }`}
            />
          </div>
        }
      />

      <Table>
        <Thead>
          <SortableTh label="Barangay" sortBy="barangay" />
          <SortableTh label="Municipality" sortBy="municipality" />
          <SortableTh
            label="Active"
            sortBy="active"
            align="right"
            className="text-emerald-600 dark:text-emerald-400"
          />
          <SortableTh
            label="VIP"
            sortBy="vip"
            align="right"
            className="text-violet-600 dark:text-violet-400"
          />
          <SortableTh
            label="Inactive"
            sortBy="inactive"
            align="right"
            className="text-amber-600 dark:text-amber-400"
          />
          <SortableTh
            label="Pullout"
            sortBy="pullout"
            align="right"
            className="text-red-500 dark:text-red-400"
          />
          <SortableTh label="Total" sortBy="total" align="right" />
        </Thead>
        <tbody>
          <TableState
            colSpan={7}
            loading={loading && rows.length === 0}
            error={error}
            empty={visible.length === 0}
            emptyMessage={
              rows.length === 0
                ? 'No subscribers have a barangay recorded.'
                : 'No barangay matches that search.'
            }
          />

          {visible.map((row) => (
            <Tr key={`${row.barangay}-${row.municipality}-${row.province}`}>
              <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {row.barangay}
              </Td>
              <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {row.municipality || '—'}
              </Td>
              <Td align="right" className="text-emerald-600 dark:text-emerald-400">
                {formatNumber(row.active)}
              </Td>
              <Td align="right" className="text-violet-600 dark:text-violet-400">
                {formatNumber(row.vip)}
              </Td>
              <Td align="right" className="text-amber-600 dark:text-amber-400">
                {formatNumber(row.inactive)}
              </Td>
              <Td align="right" className="text-red-500 dark:text-red-400">
                {formatNumber(row.pullout)}
              </Td>
              <Td align="right" className={`font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {formatNumber(row.total)}
              </Td>
            </Tr>
          ))}
        </tbody>
      </Table>
    </Card>
  );
};

export default BarangayAnalyticsPanel;
