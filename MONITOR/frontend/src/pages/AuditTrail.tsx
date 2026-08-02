import React, { useCallback, useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight, ScrollText, Search } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader } from '../components/reporting/Card';
import {
  Button,
  ErrorBanner,
  Pill,
  Table,
  TableState,
  Td,
  Th,
  Thead,
  Tr,
  useControlClass,
} from '../components/reporting/primitives';
import { useTheme } from '../hooks/useTheme';
import { adminService } from '../services/adminService';
import { AuditEntry, AuditPage } from '../types/rbac';
import { formatDateTime, todayIso } from '../utils/format';

interface AuditTrailProps {
  refreshToken: number;
}

/** Tone per action, so the events worth noticing are the ones that stand out. */
const actionTone = (action: string): 'success' | 'danger' | 'warning' | 'info' | 'neutral' => {
  if (action === 'denied') return 'danger';
  if (action.startsWith('user.') || action.startsWith('role.')) return 'warning';
  if (action === 'exported') return 'info';
  if (action.startsWith('payable.')) return 'success';

  return 'neutral';
};

/**
 * The audit trail.
 *
 * Read-only, deliberately and permanently. There is no endpoint that edits or
 * deletes a row, because a trail an administrator can prune is not a trail — and
 * the person most likely to want an entry gone is the one it holds accountable.
 *
 * What is recorded: viewing a financial or employee report, exporting a ledger,
 * marking a payable settled, every user and role change, and every request that
 * was blocked for want of a permission. That last one matters most and is
 * invisible everywhere else — the UI hides the controls a role cannot use, so a
 * blocked request did not come from the UI.
 */
const AuditTrail: React.FC<AuditTrailProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [page, setPage] = useState<AuditPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [pageNumber, setPageNumber] = useState(1);
  const [action, setAction] = useState('');
  const [actor, setActor] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  // Staged rather than applied on change, matching the rest of the app: a
  // half-typed date fires change events, and applying live would query for
  // ranges like 0002-01-01 while someone types a year.
  const [applied, setApplied] = useState({ action: '', actor: '', from: '', to: '' });

  const load = useCallback(() => {
    setLoading(true);

    adminService
      .getAuditLog({ page: pageNumber, ...applied })
      .then((result) => {
        setPage(result);
        setError(null);
      })
      .catch((err) => setError(err?.response?.data?.message ?? 'Unable to load the audit trail.'))
      .finally(() => setLoading(false));
  }, [pageNumber, applied]);

  useEffect(() => load(), [load, refreshToken]);

  const apply = () => {
    setApplied({ action, actor, from, to });
    // A filter change alters how many pages exist, so page 4 of the old result
    // is meaningless against the new one.
    setPageNumber(1);
  };

  const clear = () => {
    setAction('');
    setActor('');
    setFrom('');
    setTo('');
    setApplied({ action: '', actor: '', from: '', to: '' });
    setPageNumber(1);
  };

  return (
    <ReportingPage>
      <PageHeader
        title="Audit Trail"
        subtitle="Executive actions, access grants, and blocked requests"
      />

      {error && <ErrorBanner message={error} />}

      <Card>
        <div className="flex flex-wrap items-center gap-2">
          <select
            value={action}
            onChange={(event) => setAction(event.target.value)}
            className={controlClass}
            aria-label="Filter by action"
          >
            <option value="">All actions</option>
            {(page?.actions ?? []).map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>

          <input
            value={actor}
            onChange={(event) => setActor(event.target.value)}
            onKeyDown={(event) => event.key === 'Enter' && apply()}
            placeholder="Who…"
            className={controlClass}
            aria-label="Filter by actor"
          />

          <input
            type="date"
            value={from}
            max={to || todayIso()}
            onChange={(event) => setFrom(event.target.value)}
            className={controlClass}
            aria-label="From date"
          />
          <input
            type="date"
            value={to}
            min={from || undefined}
            max={todayIso()}
            onChange={(event) => setTo(event.target.value)}
            className={controlClass}
            aria-label="To date"
          />

          <Button variant="primary" icon={<Search size={14} />} onClick={apply} title="Apply filters" />
          <Button variant="outline" onClick={clear} title="Clear filters">
            Clear
          </Button>
        </div>
      </Card>

      <Card flush>
        <CardHeader
          title="Recorded Events"
          subtitle={page ? `${page.total.toLocaleString()} entries` : undefined}
          icon={<ScrollText size={16} />}
        />

        <Table>
          <Thead>
            <Th width="170px">When</Th>
            <Th width="120px">Who</Th>
            <Th width="130px">Action</Th>
            <Th>Detail</Th>
            <Th width="120px">IP</Th>
          </Thead>
          <tbody>
            <TableState
              colSpan={5}
              loading={loading && !page}
              error={error}
              empty={(page?.rows.length ?? 0) === 0}
              emptyMessage="No events match these filters."
            />

            {(page?.rows ?? []).map((entry) => (
              <AuditRow key={entry.id} entry={entry} />
            ))}
          </tbody>
        </Table>

        {page && page.total_pages > 1 && (
          <div
            className={`flex items-center justify-between gap-3 px-4 py-3 border-t ${
              isDarkMode ? 'border-gray-800' : 'border-gray-200'
            }`}
          >
            <span className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              Page {page.page} of {page.total_pages}
            </span>
            <span className="flex gap-1">
              <Button
                variant="outline"
                icon={<ChevronLeft size={14} />}
                disabled={page.page <= 1}
                onClick={() => setPageNumber(page.page - 1)}
                title="Previous page"
              />
              <Button
                variant="outline"
                icon={<ChevronRight size={14} />}
                disabled={page.page >= page.total_pages}
                onClick={() => setPageNumber(page.page + 1)}
                title="Next page"
              />
            </span>
          </div>
        )}
      </Card>

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        The trail is append-only — there is deliberately no way to edit or delete an entry from this
        portal. Repeat views of the same report by the same person are collapsed into one entry per
        fifteen minutes, so a dashboard left open does not bury the events worth finding.
      </p>
    </ReportingPage>
  );
};

/** One event, with its change set expandable rather than always shown. */
const AuditRow: React.FC<{ entry: AuditEntry }> = ({ entry }) => {
  const isDarkMode = useTheme();
  const [open, setOpen] = useState(false);

  const changes = entry.changes ? Object.entries(entry.changes) : [];

  return (
    <Tr>
      <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
        {formatDateTime(entry.logged_at)}
      </Td>
      <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{entry.actor}</Td>
      <Td>
        <Pill tone={actionTone(entry.action)}>{entry.action}</Pill>
      </Td>
      <Td>
        <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
          {entry.description || '—'}
        </span>

        {changes.length > 0 && (
          <>
            {/* Collapsed by default: a change set is three lines of before/after
                and expanding every row would make the page unscannable. */}
            <button
              type="button"
              onClick={() => setOpen((current) => !current)}
              className={`block text-xs mt-0.5 underline ${
                isDarkMode ? 'text-blue-400' : 'text-blue-600'
              }`}
            >
              {open ? 'Hide' : `Show ${changes.length} change${changes.length === 1 ? '' : 's'}`}
            </button>

            {open && (
              <div
                className={`mt-1.5 rounded-lg px-2 py-1.5 text-xs font-mono space-y-0.5 ${
                  isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'
                }`}
              >
                {changes.map(([field, change]) => (
                  <div key={field} className="break-words">
                    <span className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>{field}:</span>{' '}
                    <span className="text-red-500">{String(change.from ?? 'null')}</span>
                    {' → '}
                    <span className="text-emerald-600 dark:text-emerald-400">
                      {String(change.to ?? 'null')}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </>
        )}
      </Td>
      <Td className={`font-mono text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
        {entry.ip_address || '—'}
      </Td>
    </Tr>
  );
};

export default AuditTrail;
