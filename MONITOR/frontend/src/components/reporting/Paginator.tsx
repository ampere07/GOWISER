import React, { useEffect, useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button, Pill } from './primitives';
import { useTheme } from '../../hooks/useTheme';
import { formatNumber } from '../../utils/format';

/**
 * Paging for a list that is already in memory.
 *
 * ── Why client-side here, and server-side in the drill-downs ──────────
 *
 * MetricDetailModal pages on the server because its sets run to thousands of
 * rows across a fleet of databases and fetching them all to show twenty-five is
 * not an option. The lists this serves are the opposite case: a MikroTik
 * overview is one call that returns every group, every live session and every
 * queued disconnection together, and the page needs the whole of it anyway —
 * the group tab shows a live-session count per group, which is derived from the
 * session list. Paging that on the server would mean more round trips to a
 * router that is simultaneously serving authentication, in order to display
 * fewer rows of something already fetched.
 *
 * So the fetch is unchanged and only the rendering is bounded. What that buys is
 * real: a thousand live sessions is a thousand table rows, each with two
 * buttons, and the browser lays out and paints every one of them on each
 * refresh — which on the MikroTik screen is every sixty seconds by default.
 *
 * ── Why the page resets on a shorter list ─────────────────────────────
 *
 * These lists shrink under the reader: a search narrows them, and a refresh can
 * remove sessions that have dropped. Landing on page nine of a list that now has
 * four pages shows an empty table, which reads as "nobody is connected" rather
 * than as "you are past the end".
 */
export interface PageState<T> {
  /** The rows to render — one page of them. */
  rows: T[];
  page: number;
  totalPages: number;
  total: number;
  perPage: number;
  setPage: (page: number) => void;
  setPerPage: (perPage: number) => void;
}

/** Page sizes offered. 25 is the default everywhere else in the portal. */
export const PAGE_SIZES = [25, 50, 100, 250];

export const usePagination = <T,>(rows: T[], initialPerPage = 25): PageState<T> => {
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(initialPerPage);

  const total = rows.length;
  const totalPages = Math.max(1, Math.ceil(total / perPage));

  // Clamped rather than reset to 1: somebody on page 3 of a list that shrank to
  // two pages wants the end of it, not the beginning.
  useEffect(() => {
    setPage((current) => Math.min(current, totalPages));
  }, [totalPages]);

  const visible = useMemo(
    () => rows.slice((page - 1) * perPage, page * perPage),
    [rows, page, perPage]
  );

  return {
    rows: visible,
    page: Math.min(page, totalPages),
    totalPages,
    total,
    perPage,
    setPage: (next: number) => setPage(Math.max(1, Math.min(next, totalPages))),
    setPerPage: (next: number) => {
      setPerPage(next);
      // The old page number means nothing against a new page size — page 4 of
      // 25-row pages is row 76, which is inside page 1 of 250-row pages.
      setPage(1);
    },
  };
};

/**
 * The pager itself. Rendered under the table it pages.
 *
 * Hidden entirely when everything fits on one page: a control that can only be
 * pressed to no effect is noise, and these tables are frequently short.
 */
export function Paginator<T>({ state, noun }: { state: PageState<T>; noun: string }) {
  const isDarkMode = useTheme();

  const { page, totalPages, total, perPage, setPage, setPerPage } = state;

  if (total === 0) return null;

  const first = (page - 1) * perPage + 1;
  const last = Math.min(page * perPage, total);

  return (
    <div
      className={`flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 border-t ${
        isDarkMode ? 'border-gray-800' : 'border-gray-200'
      }`}
    >
      <span className={`text-sm tabular-nums ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        Showing{' '}
        <strong>
          {formatNumber(first)}–{formatNumber(last)}
        </strong>{' '}
        of <strong>{formatNumber(total)}</strong> {noun}
      </span>

      <span className="flex items-center gap-2">
        <label
          className={`flex items-center gap-1.5 text-xs ${
            isDarkMode ? 'text-gray-400' : 'text-gray-500'
          }`}
        >
          Rows
          <select
            value={perPage}
            onChange={(event) => setPerPage(Number(event.target.value))}
            className={`rounded-lg border px-2 py-1 text-xs ${
              isDarkMode
                ? 'bg-gray-900 border-gray-700 text-gray-200'
                : 'bg-white border-gray-300 text-gray-700'
            }`}
            aria-label="Rows per page"
          >
            {PAGE_SIZES.map((size) => (
              <option key={size} value={size}>
                {size}
              </option>
            ))}
          </select>
        </label>

        {totalPages > 1 && (
          <>
            <Button
              icon={<ChevronLeft size={14} />}
              onClick={() => setPage(page - 1)}
              disabled={page <= 1}
            >
              Previous
            </Button>

            <Pill tone="neutral">
              {formatNumber(page)} / {formatNumber(totalPages)}
            </Pill>

            <Button onClick={() => setPage(page + 1)} disabled={page >= totalPages}>
              Next
              <ChevronRight size={14} />
            </Button>
          </>
        )}
      </span>
    </div>
  );
}

export default Paginator;
