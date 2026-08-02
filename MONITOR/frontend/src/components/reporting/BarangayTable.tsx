import React, { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, MapPin, Search } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { formatNumber, pluralise } from '../../utils/format';
import { BarangayRow, BarangaySort } from '../../types/reporting';
import Card, { CardHeader } from './Card';
import { Bar, Table, TableState, Td, Th, Thead, TotalRow, Tr, useControlClass } from './primitives';

interface BarangayTableProps {
  rows: BarangayRow[];
  loading: boolean;
  error: string | null;
  /** Rendered in the header — the widget's own date-range control. */
  actions?: React.ReactNode;
}

const COLUMNS: { key: BarangaySort; label: string; className?: string }[] = [
  { key: 'active', label: 'Active', className: 'text-emerald-600 dark:text-emerald-400' },
  { key: 'vip', label: 'VIP', className: 'text-violet-600 dark:text-violet-400' },
  { key: 'inactive', label: 'Inactive', className: 'text-gray-500 dark:text-gray-400' },
  { key: 'pullout', label: 'Pullout', className: 'text-red-500 dark:text-red-400' },
  { key: 'total', label: 'Total' },
];

/**
 * Every barangay, sortable, with its billing-status split.
 *
 * Replaced the Top 10 league table. A ranking answered "which barangay is
 * biggest", which nobody was asking; the coverage question this now answers
 * needs the tail — the barangays with three subscribers are the ones a rollout
 * plan is about.
 *
 * With the cap gone the list can run to hundreds of rows, so it gains the two
 * things a long table needs and a top ten did not: a search box and column
 * sorting. Both are client-side — the whole set is already in the payload, and a
 * round trip to reorder rows already in memory would be slower and could
 * disagree with what is on screen.
 */
const BarangayTable: React.FC<BarangayTableProps> = ({ rows, loading, error, actions }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<BarangaySort>('total');
  const [descending, setDescending] = useState(true);

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

    // Copied before sorting: the payload is shared with the aggregate notice and
    // sorting in place would mutate what other panels read.
    return [...filtered].sort((a, b) => {
      if (sort === 'barangay') {
        const compared = a.barangay.localeCompare(b.barangay);
        return descending ? -compared : compared;
      }

      const compared = a[sort] - b[sort];

      // Ties broken by name, so the order is stable between renders rather than
      // reshuffling equal rows every time the poll refreshes.
      return compared !== 0
        ? descending
          ? -compared
          : compared
        : a.barangay.localeCompare(b.barangay);
    });
  }, [rows, search, sort, descending]);

  const applySort = (key: BarangaySort) => {
    if (key === sort) {
      setDescending((current) => !current);
      return;
    }

    setSort(key);
    // A new numeric column opens largest-first, a new name column A–Z: both are
    // the direction someone actually wants on the first click.
    setDescending(key !== 'barangay');
  };

  // Bars scale against the largest visible row, so filtering to one municipality
  // rescales them to that municipality rather than leaving every bar a stub.
  const largest = visible.reduce((max, row) => Math.max(max, row.total), 0);

  const totals = visible.reduce(
    (sum, row) => ({
      active: sum.active + row.active,
      vip: sum.vip + row.vip,
      inactive: sum.inactive + row.inactive,
      pullout: sum.pullout + row.pullout,
      total: sum.total + row.total,
    }),
    { active: 0, vip: 0, inactive: 0, pullout: 0, total: 0 }
  );

  const SortableTh: React.FC<{ column: (typeof COLUMNS)[number] }> = ({ column }) => (
    <Th align="right" className={column.className}>
      <button
        type="button"
        onClick={() => applySort(column.key)}
        className="inline-flex items-center gap-1 hover:opacity-70 transition-opacity"
        title={`Sort by ${column.label}`}
      >
        {column.label}
        {sort === column.key &&
          (descending ? <ArrowDown size={12} /> : <ArrowUp size={12} />)}
      </button>
    </Th>
  );

  return (
    <Card flush>
      <CardHeader
        title="Barangay Breakdown"
        subtitle={`${pluralise(rows.length, 'barangay', 'barangays')} with subscribers`}
        icon={<MapPin size={16} />}
        actions={
          <div className="flex flex-wrap items-center gap-2 justify-end">
            <span className="relative">
              <Search
                size={13}
                className={`absolute left-2 top-1/2 -translate-y-1/2 ${
                  isDarkMode ? 'text-gray-500' : 'text-gray-400'
                }`}
              />
              <input
                type="search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Filter barangays…"
                className={`${controlClass} !pl-7 !py-1 !text-xs w-40`}
                aria-label="Filter barangays"
              />
            </span>
            {actions}
          </div>
        }
      />

      <Table>
        <Thead>
          <Th>
            <button
              type="button"
              onClick={() => applySort('barangay')}
              className="inline-flex items-center gap-1 hover:opacity-70 transition-opacity"
              title="Sort by name"
            >
              Barangay
              {sort === 'barangay' && (descending ? <ArrowDown size={12} /> : <ArrowUp size={12} />)}
            </button>
          </Th>
          <Th>Municipality</Th>
          {COLUMNS.map((column) => (
            <SortableTh key={column.key} column={column} />
          ))}
          <Th width="90px" />
        </Thead>
        <tbody>
          <TableState
            colSpan={8}
            loading={loading && rows.length === 0}
            error={error}
            empty={visible.length === 0}
            emptyMessage={
              search.trim()
                ? `No barangay matches "${search.trim()}".`
                : 'No subscribers have a barangay recorded.'
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
              <Td align="right" className="text-emerald-600 dark:text-emerald-400 tabular-nums">
                {formatNumber(row.active)}
              </Td>
              <Td align="right" className="text-violet-600 dark:text-violet-400 tabular-nums">
                {formatNumber(row.vip)}
              </Td>
              <Td align="right" className={`tabular-nums ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                {formatNumber(row.inactive)}
              </Td>
              <Td align="right" className="text-red-500 dark:text-red-400 tabular-nums">
                {formatNumber(row.pullout)}
              </Td>
              <Td
                align="right"
                className={`font-bold tabular-nums ${isDarkMode ? 'text-white' : 'text-gray-900'}`}
              >
                {formatNumber(row.total)}
              </Td>
              <Td>
                <Bar pct={largest > 0 ? (row.total / largest) * 100 : 0} />
              </Td>
            </Tr>
          ))}

          {visible.length > 0 && (
            <TotalRow>
              <Td>Total</Td>
              <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {/* Says what the total covers when a search has narrowed it,
                    so a filtered subtotal is never read as the whole base. */}
                {search.trim() ? `${visible.length} of ${rows.length}` : `${rows.length} barangays`}
              </Td>
              <Td align="right" className="text-emerald-600 dark:text-emerald-400 tabular-nums">
                {formatNumber(totals.active)}
              </Td>
              <Td align="right" className="text-violet-600 dark:text-violet-400 tabular-nums">
                {formatNumber(totals.vip)}
              </Td>
              <Td align="right" className="tabular-nums">
                {formatNumber(totals.inactive)}
              </Td>
              <Td align="right" className="text-red-500 dark:text-red-400 tabular-nums">
                {formatNumber(totals.pullout)}
              </Td>
              <Td align="right" className="tabular-nums">
                {formatNumber(totals.total)}
              </Td>
              <Td />
            </TotalRow>
          )}
        </tbody>
      </Table>
    </Card>
  );
};

export default BarangayTable;
