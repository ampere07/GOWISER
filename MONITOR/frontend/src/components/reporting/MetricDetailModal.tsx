import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Search,
  X,
} from 'lucide-react';
import { Button, Pill, useControlClass } from './primitives';
import { useTheme } from '../../hooks/useTheme';
import { DrillDownPage } from '../../types/reporting';
import { formatNumber } from '../../utils/format';

/** One column of the drill-down table. */
export interface DetailColumn<T> {
  /** Sort key sent to the server. Omit to make the column unsortable. */
  key?: string;
  header: string;
  align?: 'left' | 'right';
  /** Rendered value. Keep it a plain scalar — this table is read, not clicked. */
  cell: (row: T) => React.ReactNode;
  /** Hidden below `md`, for columns that are context rather than identity. */
  secondary?: boolean;
}

/** A dropdown filter offered above the table. */
export interface DetailFilter {
  key: string;
  label: string;
  options: string[];
}

export interface MetricDetailQuery {
  search: string;
  sort: string;
  direction: 'asc' | 'desc';
  page: number;
  filters: Record<string, string>;
}

interface MetricDetailModalProps<T> {
  title: string;
  subtitle?: React.ReactNode;
  columns: DetailColumn<T>[];
  filters?: DetailFilter[];
  /** Fetches one page. Called on every query change, debounced for search. */
  fetchPage: (query: MetricDetailQuery) => Promise<DrillDownPage<T>>;
  /** Sort key the table opens on. */
  defaultSort?: string;
  defaultDirection?: 'asc' | 'desc';
  onClose: () => void;
}

/**
 * The drill-down behind a metric card.
 *
 * Every counter on the Group Overview answers "how many". This answers the only
 * useful next question — which — and answering it used to mean leaving the
 * portal and opening the operating system.
 *
 * ── Why the query lives on the server ─────────────────────────────────
 *
 * Search, filter, sort and paging are all server-side. Doing any of them in the
 * browser would mean fetching every matching row first, and these sets run to
 * thousands across a fleet of databases — a client-side search would be fast
 * only on the page it had already been sent, which is the one case where it is
 * not needed. The one thing done locally is debouncing: a keystroke is not a
 * query.
 *
 * ── Why sorting resets the page ───────────────────────────────────────
 *
 * Page 7 of a list sorted by name and page 7 of the same list sorted by date are
 * different rows with no relationship, so staying on 7 through a sort change
 * lands the reader somewhere arbitrary. Every control that changes the shape of
 * the result returns to page 1; only the pager itself does not.
 *
 * Generic over the row type so one component serves the metric drill-downs and
 * the subscriber-status drill-down, which have different columns and the same
 * behaviour. Two copies of this would be two places for the paging arithmetic to
 * be subtly wrong.
 */
export function MetricDetailModal<T>({
  title,
  subtitle,
  columns,
  filters = [],
  fetchPage,
  defaultSort = 'occurred_at',
  defaultDirection = 'desc',
  onClose,
}: MetricDetailModalProps<T>) {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [sort, setSort] = useState(defaultSort);
  const [direction, setDirection] = useState<'asc' | 'desc'>(defaultDirection);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Record<string, string>>({});

  const [data, setData] = useState<DrillDownPage<T> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Escape closes, as it does everywhere else in the portal. Registered on the
  // document because focus may be inside the search field.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };

    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), 350);

    return () => clearTimeout(timer);
  }, [search]);

  // Any change to the shape of the result starts again at page 1 — see the note
  // above on why paging cannot survive a re-sort.
  useEffect(() => setPage(1), [debounced, sort, direction, selected]);

  const filterKey = JSON.stringify(selected);

  const load = useCallback(() => {
    let cancelled = false;

    setLoading(true);

    fetchPage({ search: debounced, sort, direction, page, filters: selected })
      .then((result) => {
        if (cancelled) return;

        setData(result);
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;

        setError(err?.response?.data?.message ?? 'Those records could not be read.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
    // `selected` is compared by its serialised form: a fresh object of the same
    // filters must not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced, sort, direction, page, filterKey]);

  useEffect(() => load(), [load]);

  const rows = data?.rows ?? [];
  const total = data?.total ?? 0;
  const totalPages = data?.total_pages ?? 0;

  const showing = useMemo(() => {
    if (total === 0) return '0';

    const first = (page - 1) * (data?.per_page ?? 25) + 1;

    return `${formatNumber(first)}–${formatNumber(first + rows.length - 1)}`;
  }, [page, rows.length, total, data?.per_page]);

  const toggleSort = (key?: string) => {
    if (!key) return;

    if (key === sort) {
      setDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
      return;
    }

    setSort(key);
    // A new column opens descending: for a date that is newest-first, which is
    // what anyone opening a "what happened" list wants to see first.
    setDirection('desc');
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 bg-black/60"
      // A click on the backdrop closes; a click inside must not bubble to it.
      onClick={onClose}
      role="presentation"
    >
      <div
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`w-full sm:max-w-5xl h-[92vh] sm:h-auto sm:max-h-[88vh] flex flex-col rounded-t-2xl sm:rounded-2xl border ${
          isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'
        }`}
      >
        {/* ── Header ─────────────────────────────────────────────────── */}
        <div
          className={`flex items-start justify-between gap-3 px-5 py-4 border-b ${
            isDarkMode ? 'border-gray-800' : 'border-gray-200'
          }`}
        >
          <div className="min-w-0">
            <h2 className={`text-xl font-bold truncate ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
              {title}
            </h2>
            {subtitle && (
              <p className={`text-sm mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                {subtitle}
              </p>
            )}
          </div>

          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className={`flex-shrink-0 rounded-lg p-1.5 ${
              isDarkMode
                ? 'text-gray-400 hover:bg-gray-800 hover:text-white'
                : 'text-gray-500 hover:bg-gray-100 hover:text-black'
            }`}
          >
            <X size={20} />
          </button>
        </div>

        {/* ── Controls ───────────────────────────────────────────────── */}
        <div
          className={`flex flex-wrap items-center gap-2 px-5 py-3 border-b ${
            isDarkMode ? 'border-gray-800 bg-gray-950/40' : 'border-gray-200 bg-gray-50'
          }`}
        >
          <span className="relative flex-1 min-w-[200px]">
            <Search
              size={15}
              className={`absolute left-2.5 top-1/2 -translate-y-1/2 ${
                isDarkMode ? 'text-gray-500' : 'text-gray-400'
              }`}
            />
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search name, account no., address or plan…"
              className={`${controlClass} w-full !pl-8`}
              aria-label="Search records"
              autoFocus
            />
          </span>

          {filters.map((filter) => (
            <select
              key={filter.key}
              value={selected[filter.key] ?? ''}
              onChange={(event) =>
                setSelected((current) => ({ ...current, [filter.key]: event.target.value }))
              }
              className={`${controlClass} max-w-[180px]`}
              aria-label={filter.label}
            >
              <option value="">All {filter.label}</option>
              {filter.options.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          ))}

          {(debounced || Object.values(selected).some(Boolean)) && (
            <Button
              onClick={() => {
                setSearch('');
                setSelected({});
              }}
            >
              Clear
            </Button>
          )}
        </div>

        {/* Incomplete fleet is stated rather than presented as the whole one. */}
        {data?.failed && Object.keys(data.failed).length > 0 && (
          <div
            className={`flex items-start gap-2 px-5 py-2 text-sm ${
              isDarkMode ? 'bg-amber-500/10 text-amber-200' : 'bg-amber-50 text-amber-800'
            }`}
          >
            <AlertTriangle size={15} className="mt-0.5 flex-shrink-0" />
            <span>
              {Object.keys(data.failed).length} database
              {Object.keys(data.failed).length === 1 ? '' : 's'} could not be reached — this list is
              short by whatever they hold.
            </span>
          </div>
        )}

        {/* ── Table ──────────────────────────────────────────────────── */}
        <div className="flex-1 overflow-auto">
          <table className="w-full text-sm">
            <thead
              className={`sticky top-0 z-10 ${
                isDarkMode ? 'bg-gray-900' : 'bg-white'
              } border-b ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}
            >
              <tr>
                {columns.map((column) => {
                  const active = column.key === sort;

                  return (
                    <th
                      key={column.header}
                      className={`px-4 py-3 font-semibold whitespace-nowrap ${
                        column.align === 'right' ? 'text-right' : 'text-left'
                      } ${column.secondary ? 'hidden md:table-cell' : ''} ${
                        isDarkMode ? 'text-gray-300' : 'text-gray-700'
                      }`}
                    >
                      {column.key ? (
                        <button
                          type="button"
                          onClick={() => toggleSort(column.key)}
                          className={`inline-flex items-center gap-1 hover:underline ${
                            active ? (isDarkMode ? 'text-blue-300' : 'text-blue-700') : ''
                          }`}
                          aria-sort={
                            active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'
                          }
                        >
                          {column.header}
                          {active &&
                            (direction === 'asc' ? <ArrowUp size={12} /> : <ArrowDown size={12} />)}
                        </button>
                      ) : (
                        column.header
                      )}
                    </th>
                  );
                })}
              </tr>
            </thead>

            <tbody>
              {loading && rows.length === 0 ? (
                <tr>
                  <td colSpan={columns.length} className="px-4 py-16 text-center">
                    <span
                      className={`inline-flex items-center gap-2 ${
                        isDarkMode ? 'text-gray-400' : 'text-gray-500'
                      }`}
                    >
                      <Loader2 size={16} className="animate-spin" /> Loading…
                    </span>
                  </td>
                </tr>
              ) : error ? (
                <tr>
                  <td colSpan={columns.length} className="px-4 py-16 text-center">
                    <span className="inline-flex items-center gap-2 text-red-500">
                      <AlertTriangle size={16} /> {error}
                    </span>
                  </td>
                </tr>
              ) : rows.length === 0 ? (
                <tr>
                  <td
                    colSpan={columns.length}
                    className={`px-4 py-16 text-center ${
                      isDarkMode ? 'text-gray-500' : 'text-gray-500'
                    }`}
                  >
                    {debounced || Object.values(selected).some(Boolean)
                      ? 'Nothing matches those filters.'
                      : 'No records in this period.'}
                  </td>
                </tr>
              ) : (
                rows.map((row, index) => (
                  <tr
                    key={index}
                    className={`border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-100'} ${
                      isDarkMode ? 'hover:bg-gray-800/40' : 'hover:bg-gray-50'
                    }`}
                  >
                    {columns.map((column) => (
                      <td
                        key={column.header}
                        className={`px-4 py-3 ${
                          isDarkMode ? 'text-gray-200' : 'text-gray-900'
                        } ${column.align === 'right' ? 'text-right' : ''} ${
                          column.secondary ? 'hidden md:table-cell' : ''
                        }`}
                      >
                        {column.cell(row)}
                      </td>
                    ))}
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* ── Pager ──────────────────────────────────────────────────── */}
        <div
          className={`flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-t ${
            isDarkMode ? 'border-gray-800' : 'border-gray-200'
          }`}
        >
          <span className={`text-sm tabular-nums ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            {loading && rows.length > 0 && (
              <Loader2 size={13} className="inline mr-1.5 animate-spin align-[-2px]" />
            )}
            Showing <strong>{showing}</strong> of <strong>{formatNumber(total)}</strong>
          </span>

          <span className="flex items-center gap-2">
            <Button
              icon={<ChevronLeft size={14} />}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
              disabled={page <= 1 || loading}
            >
              Previous
            </Button>

            <Pill tone="neutral">
              {formatNumber(page)} / {formatNumber(Math.max(1, totalPages))}
            </Pill>

            <Button
              onClick={() => setPage((current) => current + 1)}
              disabled={page >= totalPages || loading}
            >
              Next
              <ChevronRight size={14} />
            </Button>
          </span>
        </div>
      </div>
    </div>
  );
}

export default MetricDetailModal;
