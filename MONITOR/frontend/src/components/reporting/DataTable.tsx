import React, { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown, Search } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import Card, { CardHeader } from './Card';
import { Table, TableState, Td, Th, Thead, Tr, useControlClass } from './primitives';

/** One column: how to render it, and how to sort and search it. */
export interface Column<T> {
  key: string;
  header: React.ReactNode;
  align?: 'left' | 'right' | 'center';
  width?: string;
  className?: string;
  /** Cell contents. */
  render: (row: T, index: number) => React.ReactNode;
  /**
   * The value this column sorts and searches on.
   *
   * Kept separate from `render` because what a cell *shows* and what it *means*
   * differ constantly here: a money cell renders "₱1,234.00" and must sort as
   * 1234, and a status cell renders a coloured pill that has no ordering at all.
   * Omit it and the column is neither sortable nor searched.
   */
  value?: (row: T) => string | number | null | undefined;
  /** Excludes an otherwise-valued column from the search box. */
  searchable?: boolean;
}

/** A dropdown that narrows the rows. */
export interface TableFilter<T> {
  key: string;
  label: string;
  options: { value: string; label: string }[];
  /** Return true to keep the row. Never called for the default '' option. */
  predicate: (row: T, value: string) => boolean;
}

interface DataTableProps<T> {
  title: React.ReactNode;
  subtitle?: React.ReactNode;
  icon?: React.ReactNode;
  badge?: React.ReactNode;
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T, index: number) => string;
  loading?: boolean;
  error?: string | null;
  emptyMessage?: string;
  /** Column key to sort on before the user picks one. */
  defaultSort?: string;
  defaultDescending?: boolean;
  filters?: TableFilter<T>[];
  searchPlaceholder?: string;
  /** Rendered after the toolbar controls — usually a WidgetRange. */
  actions?: React.ReactNode;
  /** A totals row, given the rows currently visible. */
  footer?: (visible: T[]) => React.ReactNode;
  /** Hides the toolbar for short, fixed tables where it is just noise. */
  showToolbar?: boolean;
}

/**
 * A table with search, sorting and filters, used by every table in the reporting
 * modules.
 *
 * Built once rather than per page because these tables were drifting: the
 * barangay table grew search and sorting when it lost its ten-row cap, and every
 * other table stayed a static list — so the same question ("which of these is
 * the biggest") was answerable on one screen and not on the next.
 *
 * All three controls are client-side. The whole result set is already in the
 * payload, so a round trip to reorder or narrow rows already in memory would be
 * slower *and* could briefly disagree with the totals rendered beside them.
 *
 * The generic is on the row type, so a column's `render` and `value` both get the
 * real row rather than `any` — which is what stops a sort comparator silently
 * reading a field that does not exist.
 */
export function DataTable<T>({
  title,
  subtitle,
  icon,
  badge,
  columns,
  rows,
  rowKey,
  loading,
  error,
  emptyMessage = 'No data for this period.',
  defaultSort,
  defaultDescending = true,
  filters = [],
  searchPlaceholder = 'Search…',
  actions,
  footer,
  showToolbar = true,
}: DataTableProps<T>) {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<string | null>(defaultSort ?? null);
  const [descending, setDescending] = useState(defaultDescending);
  const [selected, setSelected] = useState<Record<string, string>>({});

  const sortable = useMemo(
    () => new Map(columns.filter((column) => column.value).map((column) => [column.key, column])),
    [columns]
  );

  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase();

    let result = rows.filter((row) => {
      for (const filter of filters) {
        const value = selected[filter.key];

        if (value && !filter.predicate(row, value)) {
          return false;
        }
      }

      if (!needle) return true;

      return columns.some((column) => {
        if (!column.value || column.searchable === false) return false;

        return String(column.value(row) ?? '')
          .toLowerCase()
          .includes(needle);
      });
    });

    const column = sort ? sortable.get(sort) : undefined;

    if (column?.value) {
      // Copied before sorting: `rows` is the payload other panels read, and
      // sorting in place would reorder it under them.
      result = [...result].sort((a, b) => {
        const left = column.value!(a);
        const right = column.value!(b);

        // Nulls sink regardless of direction. A missing value is not "smallest",
        // and letting it lead a descending sort buries the rows that matter.
        if (left === null || left === undefined) return 1;
        if (right === null || right === undefined) return -1;

        const compared =
          typeof left === 'number' && typeof right === 'number'
            ? left - right
            : String(left).localeCompare(String(right), undefined, { numeric: true });

        return descending ? -compared : compared;
      });
    }

    return result;
  }, [rows, columns, filters, selected, search, sort, descending, sortable]);

  const applySort = (key: string) => {
    if (key === sort) {
      setDescending((current) => !current);
      return;
    }

    setSort(key);

    // Text opens A–Z and numbers open largest-first: both are the direction
    // someone actually wants on a first click.
    const sample = sortable.get(key)?.value?.(rows[0]);
    setDescending(typeof sample === 'number');
  };

  const narrowed = search.trim() !== '' || Object.values(selected).some(Boolean);

  return (
    <Card flush className="h-full">
      <CardHeader
        title={title}
        subtitle={subtitle}
        icon={icon}
        badge={badge}
        actions={
          showToolbar || actions ? (
            <div className="flex flex-wrap items-center gap-2 justify-end">
              {showToolbar && sortable.size > 0 && (
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
                    placeholder={searchPlaceholder}
                    className={`${controlClass} !pl-7 !py-1 !text-xs w-40`}
                    aria-label={`Search ${typeof title === 'string' ? title : 'table'}`}
                  />
                </span>
              )}

              {showToolbar &&
                filters.map((filter) => (
                  <select
                    key={filter.key}
                    value={selected[filter.key] ?? ''}
                    onChange={(event) =>
                      setSelected((current) => ({ ...current, [filter.key]: event.target.value }))
                    }
                    className={`${controlClass} !py-1 !text-xs`}
                    aria-label={filter.label}
                  >
                    <option value="">{filter.label}</option>
                    {filter.options.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </select>
                ))}

              {actions}
            </div>
          ) : undefined
        }
      />

      <Table>
        <Thead>
          {columns.map((column) => {
            const canSort = Boolean(column.value);
            const active = sort === column.key;

            return (
              <Th
                key={column.key}
                align={column.align}
                width={column.width}
                className={column.className}
              >
                {canSort ? (
                  <button
                    type="button"
                    onClick={() => applySort(column.key)}
                    className="inline-flex items-center gap-1 hover:opacity-70 transition-opacity"
                    title={`Sort by ${typeof column.header === 'string' ? column.header : column.key}`}
                  >
                    {column.header}
                    {active ? (
                      descending ? (
                        <ArrowDown size={12} />
                      ) : (
                        <ArrowUp size={12} />
                      )
                    ) : (
                      // A permanent affordance on every sortable column: without
                      // it, sorting is a feature you find only by clicking.
                      <ArrowUpDown size={11} className="opacity-30" />
                    )}
                  </button>
                ) : (
                  column.header
                )}
              </Th>
            );
          })}
        </Thead>

        <tbody>
          <TableState
            colSpan={columns.length}
            loading={loading && rows.length === 0}
            error={error}
            empty={visible.length === 0}
            emptyMessage={narrowed ? 'No row matches these filters.' : emptyMessage}
          />

          {visible.map((row, index) => (
            <Tr key={rowKey(row, index)}>
              {columns.map((column) => (
                <Td key={column.key} align={column.align} className={column.className}>
                  {column.render(row, index)}
                </Td>
              ))}
            </Tr>
          ))}

          {visible.length > 0 && footer?.(visible)}
        </tbody>
      </Table>

      {/* Says what a filtered subtotal covers, so it is never read as the whole. */}
      {narrowed && visible.length > 0 && (
        <p
          className={`px-4 py-2 text-xs border-t ${
            isDarkMode ? 'border-gray-800 text-gray-500' : 'border-gray-200 text-gray-500'
          }`}
        >
          Showing {visible.length} of {rows.length} rows.
        </p>
      )}
    </Card>
  );
}

export default DataTable;
