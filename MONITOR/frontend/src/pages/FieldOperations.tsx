import React from 'react';
import {
  Chart as ChartJS,
  BarController,
  BarElement,
  CategoryScale,
  Legend,
  LinearScale,
  Tooltip,
} from 'chart.js';
import { Clock, Timer } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import DataTable from '../components/reporting/DataTable';
import { DonutChart } from '../components/reporting/charts';
import {
  Bar,
  ErrorBanner,
  PanelState,
  Pill,
  Table,
  Td,
  Th,
  Thead,
  Tr,
} from '../components/reporting/primitives';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice } from '../components/reporting/DatabaseFilter';
import WidgetRange from '../components/reporting/WidgetRange';
import { Restricted, RestrictedPanel } from '../components/rbac/Restricted';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { reportingService } from '../services/reportingService';
import {
  OperationsData,
  Turnaround,
  TurnaroundByType,
  WorkQueue,
  WorkRow,
} from '../types/reporting';
import { WIDGET } from '../types/rbac';
import { formatDate, formatNumber, pluralise } from '../utils/format';

// BarController, not only BarElement — see the note in charts.tsx for why
// omitting the controller is the trap that blanks a page rather than a panel.
ChartJS.register(BarController, CategoryScale, LinearScale, BarElement, Tooltip, Legend);

interface FieldOperationsProps {
  refreshToken: number;
}

/**
 * Status colouring by meaning, so a pipeline reads at a glance.
 *
 * Matched against known vocabularies from both systems; anything unrecognised
 * falls back to grey rather than being dropped, because a new workflow state
 * must still appear.
 */
const statusTone = (label: string): string => {
  const key = label.toLowerCase().trim();

  if (['done', 'completed', 'approved', 'resolved'].includes(key)) return '#198754';
  if (['failed', 'cancelled', 'no facility', 'no slot', 'duplicate'].includes(key)) return '#dc3545';
  if (['in progress', 'for visit', 'scheduled'].includes(key)) return '#0d6efd';
  if (['pending', 'reschedule', 'rescheduled'].includes(key)) return '#fd7e14';

  return '#6c757d';
};

/**
 * Operations — the field-work queues, their backlog, and how fast they clear.
 *
 * The queues differ by source: NETMANAGER has one (installations), GOWISER has
 * three (applications, job orders, service orders). The page renders whatever the
 * driver reports rather than assuming a shape, so neither source shows an empty
 * panel for work it does not model.
 */
const FieldOperations: React.FC<FieldOperationsProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { filters, update, reset, branches, databases } = useSectionFilters('operations');

  // A range per widget, on the two panels that are genuinely period-bound.
  // The queue panels below are a statement of what is open *now* and carry no
  // range at all — a backlog filtered to a date window is not a backlog.
  const slaRange = useWidgetRange('monthly');
  const concernsRange = useWidgetRange('monthly');

  const primary = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken
  );

  const sla = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken,
    { dateFrom: slaRange.range.from, dateTo: slaRange.range.to }
  );

  const concernsSection = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken,
    { dateFrom: concernsRange.range.from, dateTo: concernsRange.range.to }
  );

  const { data, loading, error, sourceLabel, substituted } = primary;

  const first = loading && !data;

  const queues = data?.queues ?? [];

  const branchLabel = data?.branch_label;
  const showBranchLabel = branchLabel && branchLabel !== 'All branches' && branchLabel !== 'All accounts';

  return (
    <ReportingPage>
      <PageHeader
        title="Operations"
        subtitle={
          <>
            Field delivery and its backlog
            {sourceLabel && <> · {sourceLabel}</>}
            {showBranchLabel && <> · {branchLabel}</>}
          </>
        }
      />

      <SourceNotice show={substituted} sourceLabel={sourceLabel} />
      <AggregateNotice aggregate={data?.aggregate} />
      {error && <ErrorBanner message={error} />}

      <FilterBar
        filters={filters}
        onChange={update}
        onReset={reset}
        branches={branches}
        databases={databases}
        showBranch={branches.length > 0}
      />

      {/* ── Queues ────────────────────────────────────────────────────── */}
      {queues.length === 0 ? (
        <Card>
          <PanelState
            loading={first}
            empty={!first}
            emptyMessage="This system records no field-work queues."
            height={180}
          >
            <span />
          </PanelState>
        </Card>
      ) : (
        <div className={`grid grid-cols-1 gap-4 ${queues.length > 1 ? 'lg:grid-cols-3' : ''}`}>
          {queues.map((queue) => (
            <QueuePanel key={queue.key} queue={queue} />
          ))}
        </div>
      )}

      {/* ── Turnaround (SLA) ──────────────────────────────────────────── */}
      <Restricted
        require={WIDGET.operationsSla}
        fallback={<RestrictedPanel title="Turnaround (SLA)" height={200} />}
      >
        <TurnaroundPanel
          turnaround={sla.data?.turnaround}
          byType={sla.data?.turnaround_by_type ?? []}
          loading={sla.loading && !sla.data}
          rangeLabel={sla.data?.range_label}
          actions={<WidgetRange state={slaRange} />}
        />
      </Restricted>

      {/* ── Why customers call, where the source records it ────────────── */}
      {(concernsSection.data?.concerns || concernsSection.data?.repair_categories) && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {concernsSection.data.concerns && (
            <Card flush>
              <CardHeader
                title="Reported Concerns"
                subtitle={concernsSection.data.range_label}
                actions={<WidgetRange state={concernsRange} />}
              />
              <CardBody>
                <PanelState
                  loading={concernsSection.loading && !concernsSection.data}
                  empty={concernsSection.data.concerns.length === 0}
                  emptyMessage="No concerns recorded in this range."
                  height={280}
                >
                  <DonutChart
                    labels={concernsSection.data.concerns.map((row) => row.label)}
                    values={concernsSection.data.concerns.map((row) => row.count)}
                    unit="count"
                    height={280}
                  />
                </PanelState>
              </CardBody>
            </Card>
          )}

          {concernsSection.data.repair_categories && (
            <Card flush>
              <CardHeader
                title="Repair Categories"
                subtitle={concernsSection.data.range_label}
                actions={<WidgetRange state={concernsRange} />}
              />
              <CardBody>
                <PanelState
                  loading={concernsSection.loading && !concernsSection.data}
                  empty={concernsSection.data.repair_categories.length === 0}
                  emptyMessage="No repairs categorised in this range."
                  height={280}
                >
                  <DonutChart
                    labels={concernsSection.data.repair_categories.map((row) => row.label)}
                    values={concernsSection.data.repair_categories.map((row) => row.count)}
                    unit="count"
                    height={280}
                  />
                </PanelState>
              </CardBody>
            </Card>
          )}
        </div>
      )}

      {/* ── Recent work ──────────────────────────────────────────────── */}
      <DataTable<WorkRow>
        title="Recent Work"
        subtitle="Most recently opened, newest first"
        rows={data?.recent ?? []}
        rowKey={(row) => row.id}
        loading={first}
        error={error}
        emptyMessage="No work has been recorded yet."
        searchPlaceholder="Search account, subscriber…"
        // Opened-desc keeps the panel's stated promise ("newest first") as the
        // starting point; every column is sortable from there.
        defaultSort="opened"
        filters={[
          {
            key: 'status',
            label: 'All statuses',
            // Built from the rows themselves rather than a hardcoded list: both
            // systems write free-text statuses and a new one must still filter.
            options: Array.from(
              new Set((data?.recent ?? []).map((row) => row.status || 'Unspecified'))
            )
              .sort()
              .map((status) => ({ value: status, label: status })),
            predicate: (row, value) => (row.status || 'Unspecified') === value,
          },
        ]}
        columns={[
          {
            key: 'status',
            header: 'Status',
            value: (row) => row.status || 'Unspecified',
            render: (row) => (
              <span
                className="inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap"
                style={{ color: statusTone(row.status) }}
              >
                <span
                  className="w-2 h-2 rounded-full flex-shrink-0"
                  style={{ backgroundColor: statusTone(row.status) }}
                />
                {row.status || 'Unspecified'}
              </span>
            ),
          },
          {
            key: 'account',
            header: 'Account #',
            value: (row) => row.account_number,
            render: (row) => (
              <span className="font-mono text-xs text-blue-600 dark:text-blue-400">
                {row.account_number || '—'}
              </span>
            ),
          },
          {
            key: 'subscriber',
            header: 'Subscriber',
            value: (row) => row.subscriber,
            render: (row) => (
              <span className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {row.subscriber || '—'}
              </span>
            ),
          },
          {
            key: 'location',
            header: 'Location',
            value: (row) => row.location ?? '',
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {row.location || '—'}
              </span>
            ),
          },
          {
            key: 'plan',
            header: 'Plan',
            value: (row) => row.plan,
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                {row.plan || '—'}
              </span>
            ),
          },
          {
            key: 'assignee',
            header: 'Assigned to',
            value: (row) => row.assignee,
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                {row.assignee || <span className="italic text-gray-400">unassigned</span>}
              </span>
            ),
          },
          {
            key: 'opened',
            header: 'Opened',
            align: 'right',
            // Sorts on the raw timestamp, not the rendered "Aug 02, 2026" —
            // which would order alphabetically and put April before January.
            value: (row) => row.opened_at ?? '',
            searchable: false,
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {formatDate(row.opened_at)}
              </span>
            ),
          },
        ]}
      />

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Backlog counts every still-open job regardless of the date range — a job opened months ago is
        still waiting today.{' '}
        {data?.has_service_orders
          ? 'This system separates new connections from repairs, so they are reported as distinct queues.'
          : 'This system records field work as a single queue, so new connections and repairs are not distinguishable.'}
      </p>
    </ReportingPage>
  );
};

/** One work queue: its status pipeline and its backlog. */
const QueuePanel: React.FC<{ queue: WorkQueue }> = ({ queue }) => {
  const isDarkMode = useTheme();

  const total = queue.statuses.reduce((sum, status) => sum + status.count, 0);

  return (
    <Card flush className="h-full">
      <CardHeader
        title={queue.label}
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
                    {status.label}
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
const TurnaroundPanel: React.FC<{
  turnaround: OperationsData['turnaround'];
  byType: TurnaroundByType[];
  loading: boolean;
  rangeLabel?: string;
  actions?: React.ReactNode;
}> = ({ turnaround, byType, loading, rangeLabel, actions }) => {
  const isDarkMode = useTheme();

  const split = turnaround !== undefined && 'job_orders' in turnaround;

  const entries: { label: string; value: Turnaround }[] = !turnaround
    ? []
    : split
    ? [
        { label: 'Job Orders', value: (turnaround as { job_orders: Turnaround }).job_orders },
        {
          label: 'Service Orders',
          value: (turnaround as { service_orders: Turnaround }).service_orders,
        },
      ]
    : [{ label: 'Installations', value: turnaround as Turnaround }];

  return (
    <Card flush>
      <CardHeader
        title="Turnaround (SLA)"
        subtitle={
          split
            ? 'Time on site, from arrival to completion'
            : 'From opened to closed'
        }
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
                      <Measure label="Closed" value={formatNumber(entry.value.closed)} />
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

                <Table>
                  <Thead>
                    <Th>Work type</Th>
                    <Th align="right">Closed</Th>
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

                      // Scaled against the slowest type in this table, so the bar
                      // answers "which of these is the slow one" rather than
                      // comparing against an absolute nobody set.
                      const slowest = byType.reduce((max, other) => {
                        const value = other.unit === 'minutes' ? other.average_minutes : other.average_hours;
                        return Math.max(max, value ?? 0);
                      }, 0);

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

export default FieldOperations;
