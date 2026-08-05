import React, { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, MapPin, Search } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { formatNumber, pluralise } from '../../utils/format';
import { BarangayNetworkSort, BarangayRow } from '../../types/reporting';
import Card, { CardHeader } from './Card';
import { PieChart } from './charts';
import { Table, TableState, Td, Th, Thead, TotalRow, Tr, useControlClass } from './primitives';

/**
 * Slices the pie draws before the tail is gathered into one wedge.
 *
 * Barangay lists run to dozens of entries and a pie past a dozen wedges is
 * thinner than its own leader lines. The table below is uncapped, so nothing is
 * hidden — only the chart is simplified.
 */
const PIE_SLICES = 11;

interface BarangayNetworkTableProps {
  rows: BarangayRow[];
  loading: boolean;
  error?: string | null;
  actions?: React.ReactNode;
}

/**
 * Every barangay by network state: online, offline, restricted, disconnected.
 *
 * A different question from the Subscriber module's barangay table, which splits
 * the same geography by *billing* status. This one asks where service is
 * actually down — the four columns are mutually exclusive and sum to the row
 * total, which the old pairing of RADIUS counts against billing counts did not.
 * See GowiserReportsDriver::barangayBreakdown for the precedence rule.
 *
 * Sorting and filtering are client-side on purpose. The whole set is already in
 * the payload, so a round trip to reorder rows already in memory would be slower
 * *and* could disagree with what is on screen if the underlying data moved
 * between the two requests.
 */
const COLUMNS: { key: BarangayNetworkSort; label: string; className?: string }[] = [
  { key: 'online', label: 'Online', className: 'text-emerald-600 dark:text-emerald-400' },
  { key: 'offline', label: 'Offline', className: 'text-gray-500 dark:text-gray-400' },
  { key: 'restricted', label: 'Restricted', className: 'text-amber-600 dark:text-amber-400' },
  { key: 'disconnected', label: 'Disconnected', className: 'text-red-500 dark:text-red-400' },
  { key: 'total', label: 'Total' },
];

const BarangayNetworkTable: React.FC<BarangayNetworkTableProps> = ({
  rows,
  loading,
  error,
  actions,
}) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [search, setSearch] = useState('');

  /**
   * The active sort, most significant first.
   *
   * A list rather than a single column because a coverage question is usually
   * two-dimensional: "most disconnected, and among those the biggest barangay"
   * cannot be asked one column at a time. Shift-click adds a tiebreaker;
   * plain click resets to a single column, which is what someone who has never
   * heard of the modifier will do and expect.
   */
  const [sorts, setSorts] = useState<{ key: BarangayNetworkSort; descending: boolean }[]>([
    { key: 'total', descending: true },
  ]);

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

    const compare = (
      a: BarangayRow,
      b: BarangayRow,
      key: BarangayNetworkSort,
      descending: boolean
    ): number => {
      const compared =
        key === 'barangay' ? a.barangay.localeCompare(b.barangay) : a[key] - b[key];

      return descending ? -compared : compared;
    };

    // Copied before sorting: the payload is shared with the panels above and
    // sorting in place would mutate what they read.
    return [...filtered].sort((a, b) => {
      for (const { key, descending } of sorts) {
        const compared = compare(a, b, key, descending);

        if (compared !== 0) {
          return compared;
        }
      }

      // Final tiebreak by name, always ascending, so rows equal on every active
      // column keep a stable order between polls instead of reshuffling.
      return a.barangay.localeCompare(b.barangay);
    });
  }, [rows, search, sorts]);

  const totals = useMemo(
    () =>
      visible.reduce(
        (sum, row) => ({
          online: sum.online + row.online,
          offline: sum.offline + row.offline,
          restricted: sum.restricted + row.restricted,
          disconnected: sum.disconnected + row.disconnected,
          total: sum.total + row.total,
        }),
        { online: 0, offline: 0, restricted: 0, disconnected: 0, total: 0 }
      ),
    [visible]
  );

  /**
   * The pie's wedges: the biggest barangays by subscriber count, with the tail
   * gathered.
   *
   * Built from the filtered rows so the chart follows the search box — filtering
   * the table to one municipality while the pie kept showing the province would
   * put two different populations side by side under one heading. It ignores the
   * table's *sort*, though: a pie reads clockwise from its largest wedge, and
   * that is not a preference someone expresses by sorting a column by name.
   */
  const slices = useMemo(() => {
    const ranked = [...visible].sort((a, b) => b.total - a.total);

    if (ranked.length <= PIE_SLICES) {
      return ranked.map((row) => ({ label: row.barangay, count: row.total }));
    }

    const head = ranked.slice(0, PIE_SLICES - 1);
    const tail = ranked.slice(PIE_SLICES - 1);

    return [
      ...head.map((row) => ({ label: row.barangay, count: row.total })),
      {
        label: `Other barangays (${tail.length})`,
        count: tail.reduce((sum, row) => sum + row.total, 0),
      },
    ];
  }, [visible]);

  /**
   * Plain click sorts by this column alone; shift-click appends it as a
   * tiebreaker, or flips it if it is already in the list.
   */
  const applySort = (key: BarangayNetworkSort, additive: boolean) => {
    setSorts((current) => {
      const existing = current.find((entry) => entry.key === key);

      if (!additive) {
        // Re-clicking the only active column flips it; otherwise start fresh.
        // A new numeric column opens largest-first and a name column A–Z, which
        // is the direction someone wants on the first click.
        return current.length === 1 && existing
          ? [{ key, descending: !existing.descending }]
          : [{ key, descending: key !== 'barangay' }];
      }

      if (existing) {
        return current.map((entry) =>
          entry.key === key ? { ...entry, descending: !entry.descending } : entry
        );
      }

      // Capped at three. Beyond that the badges stop being readable and nobody
      // can predict what the order means anyway.
      return current.length >= 3
        ? current
        : [...current, { key, descending: key !== 'barangay' }];
    });
  };

  /** Where a column sits in the sort, or -1. Drives the rank badge. */
  const sortIndex = (key: BarangayNetworkSort) => sorts.findIndex((entry) => entry.key === key);

  const SortIndicator: React.FC<{ column: BarangayNetworkSort }> = ({ column }) => {
    const index = sortIndex(column);

    if (index < 0) return null;

    return (
      <span className="inline-flex items-center gap-0.5">
        {sorts[index].descending ? <ArrowDown size={12} /> : <ArrowUp size={12} />}
        {/* The precedence number only appears once more than one column is
            active — on a single sort it would be noise reading "1". */}
        {sorts.length > 1 && (
          <span className="text-[9px] font-bold tabular-nums opacity-70">{index + 1}</span>
        )}
      </span>
    );
  };

  const SortableTh: React.FC<{ column: (typeof COLUMNS)[number] }> = ({ column }) => (
    <Th align="right" className={column.className}>
      <button
        type="button"
        onClick={(event) => applySort(column.key, event.shiftKey)}
        className="inline-flex items-center gap-1 hover:opacity-70 transition-opacity"
        title={`Sort by ${column.label} · shift-click to add as a tiebreaker`}
      >
        {column.label}
        <SortIndicator column={column.key} />
      </button>
    </Th>
  );

  return (
    <Card flush>
      <CardHeader
        title="Barangay Breakdown"
        subtitle={
          <>
            Network state across {pluralise(rows.length, 'barangay', 'barangays')}
            {/* Named rather than left to be discovered: a modifier nobody knows
                about is a feature nobody has. Only shown once a second column is
                active, or as a hint when only one is. */}
            {sorts.length > 1 ? (
              <>
                {' · sorted by '}
                {sorts
                  .map(
                    (entry) =>
                      `${entry.key === 'barangay' ? 'name' : entry.key}${
                        entry.descending ? ' ↓' : ' ↑'
                      }`
                  )
                  .join(', then ')}
              </>
            ) : (
              ' · shift-click a column to add a tiebreaker'
            )}
          </>
        }
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

      {/* Pie on the left, the figures on the right — the same pairing the plan
          panel uses, for the same reason: the chart says which barangays carry
          the base and the table says by how much. The pie plots subscriber
          totals rather than the four network states, because "where are our
          customers" is the question a map-shaped chart answers; "how many are
          down" is a column comparison and belongs in the table. */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 px-4 sm:px-5 pt-4">
        <div className="min-w-0">
          <PieChart
            labels={slices.map((slice) => slice.label)}
            values={slices.map((slice) => slice.count)}
            unit="count"
            height={300}
          />
        </div>

        <div className="min-w-0 grid grid-cols-2 sm:grid-cols-4 gap-2 content-start">
          {COLUMNS.filter((column) => column.key !== 'total').map((column) => (
            <div
              key={column.key}
              className={`rounded-lg px-2.5 py-2 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
            >
              <p className={`text-[11px] ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                {column.label}
              </p>
              <p className={`text-lg font-bold tabular-nums ${column.className ?? ''}`}>
                {formatNumber(totals[column.key as keyof typeof totals])}
              </p>
            </div>
          ))}
          <div className={`rounded-lg px-2.5 py-2 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}>
            <p className={`text-[11px] ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              Subscribers
            </p>
            <p
              className={`text-lg font-bold tabular-nums ${
                isDarkMode ? 'text-white' : 'text-gray-900'
              }`}
            >
              {formatNumber(totals.total)}
            </p>
          </div>
        </div>
      </div>

      <div className="max-h-[520px] overflow-y-auto">
        <Table>
          <Thead>
            <Th>
              <button
                type="button"
                onClick={(event) => applySort('barangay', event.shiftKey)}
                className="inline-flex items-center gap-1 hover:opacity-70 transition-opacity"
                title="Sort by name · shift-click to add as a tiebreaker"
              >
                Barangay
                <SortIndicator column="barangay" />
              </button>
            </Th>
            <Th>Municipality</Th>
            {COLUMNS.map((column) => (
              <SortableTh key={column.key} column={column} />
            ))}
          </Thead>
          <tbody>
            <TableState
              colSpan={7}
              loading={loading && rows.length === 0}
              error={error ?? null}
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
                  {formatNumber(row.online)}
                </Td>
                <Td
                  align="right"
                  className={`tabular-nums ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}
                >
                  {formatNumber(row.offline)}
                </Td>
                <Td align="right" className="text-amber-600 dark:text-amber-400 tabular-nums">
                  {formatNumber(row.restricted)}
                </Td>
                <Td align="right" className="text-red-500 dark:text-red-400 tabular-nums">
                  {formatNumber(row.disconnected)}
                </Td>
                <Td
                  align="right"
                  className={`font-bold tabular-nums ${isDarkMode ? 'text-white' : 'text-gray-900'}`}
                >
                  {formatNumber(row.total)}
                </Td>
              </Tr>
            ))}

            {visible.length > 0 && (
              <TotalRow>
                <Td>Total</Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {/* Says what the total covers when a search has narrowed it, so
                      a filtered subtotal is never read as the whole base. */}
                  {search.trim() ? `${visible.length} of ${rows.length}` : `${rows.length} barangays`}
                </Td>
                <Td align="right" className="text-emerald-600 dark:text-emerald-400 tabular-nums">
                  {formatNumber(totals.online)}
                </Td>
                <Td align="right" className="tabular-nums">
                  {formatNumber(totals.offline)}
                </Td>
                <Td align="right" className="text-amber-600 dark:text-amber-400 tabular-nums">
                  {formatNumber(totals.restricted)}
                </Td>
                <Td align="right" className="text-red-500 dark:text-red-400 tabular-nums">
                  {formatNumber(totals.disconnected)}
                </Td>
                <Td align="right" className="tabular-nums">
                  {formatNumber(totals.total)}
                </Td>
              </TotalRow>
            )}
          </tbody>
        </Table>
      </div>
    </Card>
  );
};

export default BarangayNetworkTable;
