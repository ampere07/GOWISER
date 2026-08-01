import React, { useMemo } from 'react';
import {
  Chart as ChartJS,
  BarElement,
  CategoryScale,
  Legend,
  LinearScale,
  Tooltip,
} from 'chart.js';
import { Bar as BarChart } from 'react-chartjs-2';
import { AlertTriangle, ClipboardList, Clock, Wrench } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import StatCard from '../components/reporting/StatCard';
import { DonutChart } from '../components/reporting/charts';
import {
  ErrorBanner,
  PanelState,
  Pill,
  Table,
  TableState,
  Td,
  Th,
  Thead,
  Tr,
} from '../components/reporting/primitives';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice } from '../components/reporting/DatabaseFilter';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { reportingService } from '../services/reportingService';
import { OperationsData, Turnaround, WorkQueue } from '../types/reporting';
import { formatDate, formatNumber, pluralise } from '../utils/format';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

interface FieldOperationsProps {
  refreshToken: number;
}

const OPENED_COLOR = '#0d6efd';
const CLOSED_COLOR = '#198754';

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

  const { data, loading, error, sourceLabel, substituted } = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken
  );

  const first = loading && !data;

  const queues = data?.queues ?? [];

  const totalOpen = queues.reduce((sum, queue) => sum + queue.backlog.open, 0);

  const oldestDays = queues.reduce<number | null>((max, queue) => {
    const age = queue.backlog.oldest_age_days;
    if (age === null) return max;
    return max === null ? age : Math.max(max, age);
  }, null);

  const opened = (data?.series ?? []).reduce((sum, point) => sum + point.opened, 0);
  const closed = (data?.series ?? []).reduce((sum, point) => sum + point.closed, 0);

  const grid = isDarkMode ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
  const tick = isDarkMode ? 'rgba(226,232,240,0.65)' : 'rgba(30,41,59,0.65)';

  /** Opened vs closed per day. Counts, so the axis is integers, not currency. */
  const throughput = useMemo(
    () => ({
      data: {
        labels: (data?.series ?? []).map((point) => point.label),
        datasets: [
          {
            label: 'Opened',
            data: (data?.series ?? []).map((point) => point.opened),
            backgroundColor: `${OPENED_COLOR}cc`,
            borderColor: OPENED_COLOR,
            borderWidth: 1,
            borderRadius: 3,
          },
          {
            label: 'Closed',
            data: (data?.series ?? []).map((point) => point.closed),
            backgroundColor: `${CLOSED_COLOR}cc`,
            borderColor: CLOSED_COLOR,
            borderWidth: 1,
            borderRadius: 3,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index' as const, intersect: false },
        plugins: {
          legend: {
            position: 'top' as const,
            labels: { color: tick, boxWidth: 12, padding: 16, font: { size: 11 } },
          },
          tooltip: {
            backgroundColor: isDarkMode ? '#1e293b' : '#ffffff',
            titleColor: isDarkMode ? '#f1f5f9' : '#0f172a',
            bodyColor: isDarkMode ? '#f1f5f9' : '#0f172a',
            borderColor: isDarkMode ? '#334155' : '#e2e8f0',
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8,
          },
        },
        scales: {
          y: {
            beginAtZero: true,
            grid: { color: grid },
            // Counts are whole jobs; a "2.5 installations" gridline is nonsense.
            ticks: { color: tick, font: { size: 10 }, precision: 0 },
          },
          x: {
            grid: { display: false },
            ticks: { color: tick, font: { size: 10 }, maxRotation: 45 },
          },
        },
      },
    }),
    [data, grid, tick, isDarkMode]
  );

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

      {/* ── Headline ──────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Open work"
          value={formatNumber(totalOpen)}
          tone={totalOpen > 0 ? 'warning' : 'success'}
          icon={<ClipboardList size={20} />}
          loading={first}
          caption="across every queue, all time"
        />
        <StatCard
          label="Oldest open"
          value={oldestDays === null ? 'None' : `${formatNumber(oldestDays)}d`}
          tone={oldestDays !== null && oldestDays > 30 ? 'danger' : 'neutral'}
          icon={<AlertTriangle size={20} />}
          loading={first}
          caption={oldestDays === null ? 'nothing waiting' : 'longest a job has waited'}
        />
        <StatCard
          label="Opened in range"
          value={formatNumber(opened)}
          tone="info"
          icon={<Wrench size={20} />}
          loading={first}
          caption={data?.range_label}
        />
        <StatCard
          label="Closed in range"
          value={formatNumber(closed)}
          tone="success"
          icon={<Clock size={20} />}
          loading={first}
          // Net movement is the number that says whether the backlog is growing.
          caption={
            data
              ? closed >= opened
                ? `clearing faster than arriving (+${formatNumber(closed - opened)})`
                : `falling behind by ${formatNumber(opened - closed)}`
              : undefined
          }
        />
      </div>

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

      {/* ── Throughput ────────────────────────────────────────────────── */}
      <Card flush>
        <CardHeader title="Opened vs Closed" subtitle={data?.range_label} />
        <CardBody>
          <PanelState
            loading={first}
            empty={(data?.series.length ?? 0) === 0}
            emptyMessage="No work was opened or closed in this range."
            height={300}
          >
            <div style={{ height: 300 }} className="relative">
              <BarChart options={throughput.options as any} data={throughput.data as any} />
            </div>
          </PanelState>
        </CardBody>
      </Card>

      {/* ── Turnaround ────────────────────────────────────────────────── */}
      <TurnaroundPanel turnaround={data?.turnaround} loading={first} />

      {/* ── Why customers call, where the source records it ────────────── */}
      {(data?.concerns || data?.repair_categories) && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {data.concerns && (
            <Card flush>
              <CardHeader title="Reported Concerns" subtitle={data.range_label} />
              <CardBody>
                <PanelState
                  loading={first}
                  empty={data.concerns.length === 0}
                  emptyMessage="No concerns recorded in this range."
                  height={280}
                >
                  <DonutChart
                    labels={data.concerns.map((row) => row.label)}
                    values={data.concerns.map((row) => row.count)}
                    unit="count"
                    height={280}
                  />
                </PanelState>
              </CardBody>
            </Card>
          )}

          {data.repair_categories && (
            <Card flush>
              <CardHeader title="Repair Categories" subtitle={data.range_label} />
              <CardBody>
                <PanelState
                  loading={first}
                  empty={data.repair_categories.length === 0}
                  emptyMessage="No repairs categorised in this range."
                  height={280}
                >
                  <DonutChart
                    labels={data.repair_categories.map((row) => row.label)}
                    values={data.repair_categories.map((row) => row.count)}
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
      <Card flush>
        <CardHeader title="Recent Work" subtitle="Most recently opened, newest first" />
        <Table>
          <Thead>
            <Th>Status</Th>
            <Th>Account #</Th>
            <Th>Subscriber</Th>
            <Th>Location</Th>
            <Th>Plan</Th>
            <Th>Assigned to</Th>
            <Th align="right">Opened</Th>
          </Thead>
          <tbody>
            <TableState
              colSpan={7}
              loading={first}
              error={error}
              empty={(data?.recent.length ?? 0) === 0}
              emptyMessage="No work has been recorded yet."
            />

            {(data?.recent ?? []).map((row) => (
              <Tr key={row.id}>
                <Td>
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
                </Td>
                <Td className="font-mono text-xs text-blue-600 dark:text-blue-400">
                  {row.account_number || '—'}
                </Td>
                <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {row.subscriber || '—'}
                </Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {row.location || '—'}
                </Td>
                <Td className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>{row.plan || '—'}</Td>
                <Td className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                  {row.assignee || <span className="italic text-gray-400">unassigned</span>}
                </Td>
                <Td align="right" className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {formatDate(row.opened_at)}
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>

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
 * Turnaround, in whichever unit the source measures.
 *
 * NETMANAGER ages a ticket from opened to closed, which is hours or days.
 * GOWISER stamps actual time on site, which is minutes. Presenting both as one
 * "turnaround" number would invite comparing quantities that are not comparable,
 * so each is labelled with what it measures.
 */
const TurnaroundPanel: React.FC<{
  turnaround: OperationsData['turnaround'] | undefined;
  loading: boolean;
}> = ({ turnaround, loading }) => {
  const isDarkMode = useTheme();

  if (!turnaround) {
    return (
      <Card>
        <PanelState loading={loading} empty={!loading} emptyMessage="No turnaround data." height={100}>
          <span />
        </PanelState>
      </Card>
    );
  }

  const split = 'job_orders' in turnaround;

  const entries: { label: string; value: Turnaround }[] = split
    ? [
        { label: 'Job Orders', value: (turnaround as { job_orders: Turnaround }).job_orders },
        { label: 'Service Orders', value: (turnaround as { service_orders: Turnaround }).service_orders },
      ]
    : [{ label: 'Installations', value: turnaround as Turnaround }];

  return (
    <Card flush>
      <CardHeader
        title="Turnaround"
        subtitle={split ? 'Time on site, from arrival to completion' : 'From opened to closed'}
        icon={<Clock size={16} />}
      />
      <CardBody>
        <div className={`grid grid-cols-1 ${split ? 'sm:grid-cols-2' : ''} gap-4`}>
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
                <p className={`text-sm font-semibold ${isDarkMode ? 'text-gray-200' : 'text-gray-800'}`}>
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

        <p className={`mt-3 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          Measured only on work that closed inside the range. Still-open jobs are excluded, so the
          average does not fall every time a new one is created.
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
