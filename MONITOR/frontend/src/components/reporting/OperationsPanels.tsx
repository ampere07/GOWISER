import React from 'react';
import { Clock, Timer } from 'lucide-react';
import Card, { CardHeader, CardBody } from './Card';
import { Bar, PanelState, Pill, Table, Td, Th, Thead, Tr } from './primitives';
import { useTheme } from '../../hooks/useTheme';
import { OperationsData, Turnaround, TurnaroundByType, WorkQueue } from '../../types/reporting';
import { formatDate, formatNumber, pluralise } from '../../utils/format';

/**
 * The two Operations panels, shared by the Operations page and the Group
 * Overview.
 *
 * They were local to FieldOperations until the Group Overview needed the same
 * two. Copying them would have put the definition of "how fast is the field
 * team" in two files that drift — and these two panels are exactly the ones an
 * executive quotes back at a field manager, so the two screens disagreeing is
 * the failure mode that matters most.
 */

/**
 * Status colouring by meaning, so a pipeline reads at a glance.
 *
 * Matched against known vocabularies from both systems; anything unrecognised
 * falls back to grey rather than being dropped, because a new workflow state
 * must still appear.
 */
export const statusTone = (label: string): string => {
  const key = label.toLowerCase().trim();

  if (['done', 'completed', 'approved', 'resolved', 'installed', 'repaired'].includes(key)) return '#198754';
  if (['failed', 'cancelled', 'no facility', 'no slot', 'duplicate'].includes(key)) return '#dc3545';
  if (['in progress', 'for visit', 'scheduled'].includes(key)) return '#0d6efd';
  if (['pending', 'reschedule', 'rescheduled'].includes(key)) return '#fd7e14';

  return '#6c757d';
};

/**
 * Plain-English names for the statuses and queues the drivers report.
 *
 * The database calls them "job_orders" and "Done"; the business calls them
 * installations and "Installed". The Group Overview is read by people who never
 * see the schema, and the three work cards this panel replaced there had already
 * done this translation — dropping it would have been a step backwards in
 * readability even while the panel itself gained the backlog and the ageing.
 *
 * Keyed per queue, because the same status word means different work in each:
 * "Done" is an installation in one queue and a repair in the next, and an
 * executive reading three panels side by side needs to be able to tell which
 * "Done" they are looking at without checking the panel title.
 *
 * Unmapped statuses fall through unchanged. A workflow state added in the
 * operating system must still appear here, under its own name, rather than
 * being silently swallowed by a lookup that does not know it yet.
 */
const QUEUE_LABELS: Record<string, string> = {
  applications: 'New Customer Applications',
  job_orders: 'New Installations',
  service_orders: 'Repair & Maintenance',
  installations: 'New Installations',
};

const STATUS_LABELS: Record<string, Record<string, string>> = {
  applications: {
    done: 'Scheduled for setup',
    scheduled: 'Scheduled for setup',
    pending: 'To be processed',
    'in progress': 'To be processed',
    'no facility': 'No facility available',
    'no slot': 'No facility available',
    reschedule: 'Rescheduled visit',
    rescheduled: 'Rescheduled visit',
    failed: 'Failed',
    cancelled: 'Cancelled',
  },
  job_orders: {
    done: 'Installed',
    completed: 'Installed',
    'in progress': 'To be installed',
    'for visit': 'To be installed',
    scheduled: 'To be installed',
    pending: 'To be installed',
    reschedule: 'Rescheduled installation',
    rescheduled: 'Rescheduled installation',
    failed: 'Failed to install',
    cancelled: 'Cancelled',
  },
  service_orders: {
    done: 'Repaired',
    completed: 'Repaired',
    resolved: 'Repaired',
    'in progress': 'To be repaired',
    'for visit': 'To be repaired',
    scheduled: 'To be repaired',
    pending: 'To be repaired',
    reschedule: 'Rescheduled repair',
    rescheduled: 'Rescheduled repair',
    failed: 'Failed to repair',
    cancelled: 'Cancelled',
  },
};

/** The queue's business name, falling back to whatever the driver called it. */
const queueLabel = (queue: WorkQueue): string =>
  QUEUE_LABELS[queue.key] ?? queue.label;

/** The status's business name within this queue, unchanged when unmapped. */
const statusLabel = (queueKey: string, label: string): string =>
  STATUS_LABELS[queueKey]?.[label.toLowerCase().trim()] ?? label;

/**
 * One work queue: its status pipeline and its backlog.
 *
 * `plainLabels` is off by default so the Operations page keeps reporting the
 * statuses under the names its own source records — a field manager works in
 * that vocabulary and renaming it under them would make the page harder to
 * reconcile against the system they administer. The Group Overview turns it on.
 */
export const QueuePanel: React.FC<{ queue: WorkQueue; plainLabels?: boolean }> = ({
  queue,
  plainLabels = false,
}) => {
  const isDarkMode = useTheme();

  const total = queue.statuses.reduce((sum, status) => sum + status.count, 0);
  const title = plainLabels ? queueLabel(queue) : queue.label;

  return (
    <Card flush className="h-full">
      <CardHeader
        title={title}
        badge={pluralise(total, 'record')}
        actions={
          queue.backlog.open > 0 ? (
            <Pill tone="warning">{formatNumber(queue.backlog.open)} open</Pill>
          ) : (
            <Pill tone="success">clear</Pill>
          )
        }
      />
      <CardBody>
        {queue.statuses.length === 0 ? (
          <p className={`text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            Nothing opened in this range.
          </p>
        ) : (
          <div className="space-y-2">
            {queue.statuses.map((status) => (
              <div key={status.label}>
                <div className="flex items-baseline justify-between gap-2 mb-1">
                  <span className={`text-sm truncate ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                    {plainLabels ? statusLabel(queue.key, status.label) : status.label}
                  </span>
                  <span className="text-sm font-bold whitespace-nowrap">
                    {formatNumber(status.count)}
                  </span>
                </div>
                {/* Full-width track: these bars compare statuses within one
                    queue, so they share the queue's own total as the scale. */}
                <span
                  className={`block h-1.5 rounded-full overflow-hidden ${
                    isDarkMode ? 'bg-gray-700' : 'bg-gray-200'
                  }`}
                >
                  <span
                    className="block h-full rounded-full transition-all duration-500"
                    style={{
                      width: `${total > 0 ? (status.count / total) * 100 : 0}%`,
                      backgroundColor: statusTone(status.label),
                    }}
                  />
                </span>
              </div>
            ))}
          </div>
        )}

        {queue.backlog.oldest_opened_at && (
          <p
            className={`mt-3 pt-3 border-t text-xs ${
              isDarkMode ? 'border-gray-800 text-gray-400' : 'border-gray-200 text-gray-500'
            }`}
          >
            Oldest open item waiting since {formatDate(queue.backlog.oldest_opened_at)}
            {queue.backlog.oldest_age_days !== null && (
              <> · {formatNumber(queue.backlog.oldest_age_days)} days</>
            )}
          </p>
        )}
      </CardBody>
    </Card>
  );
};

/**
 * Turnaround, in whichever unit the source measures, segmented by work type.
 *
 * NETMANAGER ages a ticket from opened to closed, which is hours. GOWISER stamps
 * actual time on site, which is minutes. Presenting both as one "turnaround"
 * number would invite comparing quantities that are not comparable, so each row
 * carries its own unit and they are never blended.
 *
 * The by-type table is the part that leads somewhere. A single blended average
 * tells a field manager the queue is slow; the split says *which* work is slow,
 * which is the thing that can be acted on.
 */
export const TurnaroundPanel: React.FC<{
  turnaround: OperationsData['turnaround'];
  byType: TurnaroundByType[];
  loading: boolean;
  rangeLabel?: string;
  actions?: React.ReactNode;
}> = ({ turnaround, byType, loading, rangeLabel, actions }) => {
  const isDarkMode = useTheme();

  const split = turnaround !== undefined && 'job_orders' in turnaround;

  /**
   * The three figures per queue, with the count named for what it counted.
   *
   * "Closed" was the same word over both columns, and it is the schema's word
   * rather than the business's — a job order that closes has been *installed*
   * and a service order that closes has been *repaired*, which is the outcome
   * anybody reading an SLA panel is actually counting. Worse, "Closed" also
   * covers Cancelled and Failed in the drivers' own vocabulary, so the shared
   * label invited reading a cancelled job as a completed one.
   *
   * Carried per entry rather than derived from the label, so a source that adds
   * a fourth queue has to say what its completions are called instead of
   * inheriting a word that happens to fit the last one.
   */
  const entries: { label: string; closedLabel: string; value: Turnaround }[] = !turnaround
    ? []
    : split
    ? [
        {
          label: 'Job Orders',
          closedLabel: 'Installed',
          value: (turnaround as { job_orders: Turnaround }).job_orders,
        },
        {
          label: 'Service Orders',
          closedLabel: 'Repaired',
          value: (turnaround as { service_orders: Turnaround }).service_orders,
        },
      ]
    : [{ label: 'Installations', closedLabel: 'Installed', value: turnaround as Turnaround }];

  // Computed once for the whole table rather than inside the row loop, where it
  // was quadratic: the scale is a property of the table, not of a row, and a
  // source with dozens of work-order types re-derived it dozens of times.
  const slowest = byType.reduce((max, other) => {
    const value = other.unit === 'minutes' ? other.average_minutes : other.average_hours;
    return Math.max(max, value ?? 0);
  }, 0);

  return (
    <Card flush>
      <CardHeader
        title="Turnaround (SLA)"
        subtitle={split ? 'Time on site, from arrival to completion' : 'From opened to closed'}
        icon={<Clock size={16} />}
        actions={actions}
      />
      <CardBody>
        <PanelState
          loading={loading}
          empty={entries.length === 0 && byType.length === 0}
          emptyMessage="No work closed inside this range."
          height={140}
        >
          <>
            <div className={`grid grid-cols-1 ${entries.length > 1 ? 'sm:grid-cols-2' : ''} gap-4`}>
              {entries.map((entry) => {
                const inMinutes = entry.value.average_minutes !== undefined;
                const average = inMinutes ? entry.value.average_minutes : entry.value.average_hours;
                const longest = inMinutes ? entry.value.longest_minutes : entry.value.longest_hours;
                const unit = inMinutes ? 'min' : 'h';

                return (
                  <div
                    key={entry.label}
                    className={`rounded-lg px-4 py-3 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
                  >
                    <p
                      className={`text-sm font-semibold ${
                        isDarkMode ? 'text-gray-200' : 'text-gray-800'
                      }`}
                    >
                      {entry.label}
                    </p>
                    <div className="mt-2 grid grid-cols-3 gap-3">
                      <Measure label={entry.closedLabel} value={formatNumber(entry.value.closed)} />
                      <Measure
                        label="Average"
                        value={average === null || average === undefined ? '—' : `${average}${unit}`}
                      />
                      <Measure
                        label="Longest"
                        value={
                          longest === null || longest === undefined
                            ? '—'
                            : `${formatNumber(longest)}${unit}`
                        }
                      />
                    </div>
                  </div>
                );
              })}
            </div>

            {byType.length > 0 && (
              <div className="mt-4">
                <p
                  className={`flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide mb-2 ${
                    isDarkMode ? 'text-gray-400' : 'text-gray-500'
                  }`}
                >
                  <Timer size={13} />
                  Average completion by work-order type
                  {rangeLabel && (
                    <span className="font-normal normal-case tracking-normal">· {rangeLabel}</span>
                  )}
                </p>

                <div className="overflow-x-auto">
                  <Table>
                    <Thead>
                      <Th>Work type</Th>
                      {/* One column over both queues here, so it keeps the
                          neutral word: this table mixes installations and
                          repairs on the same axis and neither "Installed" nor
                          "Repaired" would be true of every row. */}
                      <Th align="right">Completed</Th>
                      <Th align="right">Average</Th>
                      <Th align="right">Longest</Th>
                      <Th width="110px" />
                    </Thead>
                    <tbody>
                      {byType.map((row) => {
                        const minutes = row.unit === 'minutes';
                        const average = minutes ? row.average_minutes : row.average_hours;
                        const longest = minutes ? row.longest_minutes : row.longest_hours;
                        const unit = minutes ? 'min' : 'h';

                        return (
                          <Tr key={`${row.label}-${row.unit}`}>
                            <Td className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                              {row.label}
                            </Td>
                            <Td align="right" className="tabular-nums">
                              {formatNumber(row.closed)}
                            </Td>
                            <Td align="right" className="font-semibold tabular-nums">
                              {average === null || average === undefined ? '—' : `${average} ${unit}`}
                            </Td>
                            <Td
                              align="right"
                              className={`tabular-nums ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}
                            >
                              {longest === null || longest === undefined
                                ? '—'
                                : `${formatNumber(longest)} ${unit}`}
                            </Td>
                            <Td>
                              {/* Scaled against the slowest type in this table, so
                                  the bar answers "which of these is the slow one"
                                  rather than comparing against an absolute nobody set. */}
                              <Bar
                                pct={slowest > 0 ? ((average ?? 0) / slowest) * 100 : 0}
                                color="#fd7e14"
                              />
                            </Td>
                          </Tr>
                        );
                      })}
                    </tbody>
                  </Table>
                </div>
              </div>
            )}
          </>
        </PanelState>

        <p className={`mt-3 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          Measured only on work that closed inside the range. Still-open jobs are excluded, so the
          average does not fall every time a new one is created. Minutes are actual time on site;
          hours are the age of a ticket — the two systems measure different things and are never
          blended into one figure.
        </p>
      </CardBody>
    </Card>
  );
};

const Measure: React.FC<{ label: string; value: React.ReactNode }> = ({ label, value }) => {
  const isDarkMode = useTheme();

  return (
    <div>
      <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{label}</p>
      <p className="text-lg font-bold">{value}</p>
    </div>
  );
};
