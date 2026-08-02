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
import { AlertTriangle, ClipboardList, Clock, HelpCircle, Timer, Wrench } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import StatCard from '../components/reporting/StatCard';
import { DonutChart } from '../components/reporting/charts';
import {
  Bar,
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
import WidgetRange from '../components/reporting/WidgetRange';
import { Restricted, RestrictedPanel } from '../components/rbac/Restricted';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { reportingService } from '../services/reportingService';
import { OperationsData, Turnaround, TurnaroundByType, WorkQueue } from '../types/reporting';
import { WIDGET } from '../types/rbac';
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

  // One range per widget, replacing the page-level period filter. The queue
  // metrics and the throughput chart are the two things people actually want on
  // different windows — "what is open right now" against "what happened last
  // quarter" — so they get separate controls.
  const metricsRange = useWidgetRange('monthly');
  const throughputRange = useWidgetRange('monthly');
  const slaRange = useWidgetRange('monthly');
  const concernsRange = useWidgetRange('monthly');

  const primary = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken,
    { dateFrom: metricsRange.range.from, dateTo: metricsRange.range.to }
  );

  const throughput_ = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken,
    { dateFrom: throughputRange.range.from, dateTo: throughputRange.range.to }
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

  const totalOpen = queues.reduce((sum, queue) => sum + queue.backlog.open, 0);

  const oldestDays = queues.reduce<number | null>((max, queue) => {
    const age = queue.backlog.oldest_age_days;
    if (age === null) return max;
    return max === null ? age : Math.max(max, age);
  }, null);

  // Counted from the metrics widget's own range, so "opened in range" means the
  // range shown on that card and not whatever another panel happens to be on.
  const opened = (data?.series ?? []).reduce((sum, point) => sum + point.opened, 0);
  const closed = (data?.series ?? []).reduce((sum, point) => sum + point.closed, 0);

  const grid = isDarkMode ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
  const tick = isDarkMode ? 'rgba(226,232,240,0.65)' : 'rgba(30,41,59,0.65)';

  /**
   * Opened vs closed per day. Counts, so the axis is integers, not currency.
   *
   * Memoised on the payload rather than defaulted inline: `?? []` builds a fresh
   * array every render, which would change the chart's dependencies each time
   * and rebuild it on every poll tick.
   */
  const throughputSeries = useMemo(
    () => throughput_.data?.series ?? [],
    [throughput_.data]
  );

  const throughput = useMemo(
    () => ({
      data: {
        labels: throughputSeries.map((point) => point.label),
        datasets: [
          {
            label: 'Opened',
            data: throughputSeries.map((point) => point.opened),
            backgroundColor: `${OPENED_COLOR}cc`,
            borderColor: OPENED_COLOR,
            borderWidth: 1,
            borderRadius: 3,
          },
          {
            label: 'Closed',
            data: throughputSeries.map((point) => point.closed),
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
    [throughputSeries, grid, tick, isDarkMode]
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
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
          <div className="min-w-0">
            <h3 className={`font-bold text-base ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
              Work Queue Metrics
            </h3>
            <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              Applications, Job Orders, Service Orders and Work Orders
            </p>
          </div>
          <WidgetRange state={metricsRange} />
        </div>

        {/* Two of these four ignore the date range and two are bounded by it,
            which is a genuine and confusing difference. Each carries a tooltip
            with its precise definition rather than leaving a reader to infer it
            from a caption. */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <MetricWithDefinition definition={SLA_DEFINITIONS.open_work}>
            <StatCard
              label="Open Work"
              value={formatNumber(totalOpen)}
              tone={totalOpen > 0 ? 'warning' : 'success'}
              icon={<ClipboardList size={20} />}
              loading={first}
              caption="unresolved, all time"
            />
          </MetricWithDefinition>

          <MetricWithDefinition definition={SLA_DEFINITIONS.oldest_open}>
            <StatCard
              label="Oldest Open"
              value={oldestDays === null ? 'None' : `${formatNumber(oldestDays)}d`}
              tone={oldestDays !== null && oldestDays > 30 ? 'danger' : 'neutral'}
              icon={<AlertTriangle size={20} />}
              loading={first}
              caption={oldestDays === null ? 'nothing waiting' : 'days the longest has waited'}
            />
          </MetricWithDefinition>

          <MetricWithDefinition definition={SLA_DEFINITIONS.opened_in_range}>
            <StatCard
              label="Opened in Range"
              value={formatNumber(opened)}
              tone="info"
              icon={<Wrench size={20} />}
              loading={first}
              caption={data?.range_label}
            />
          </MetricWithDefinition>

          <MetricWithDefinition definition={SLA_DEFINITIONS.closed_in_range}>
            <StatCard
              label="Closed in Range"
              value={formatNumber(closed)}
              tone="success"
              icon={<Clock size={20} />}
              loading={first}
              // Net movement is the number that says whether the backlog is
              // growing.
              caption={
                data
                  ? closed >= opened
                    ? `clearing faster than arriving (+${formatNumber(closed - opened)})`
                    : `falling behind by ${formatNumber(opened - closed)}`
                  : undefined
              }
            />
          </MetricWithDefinition>
        </div>
      </Card>

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
        <CardHeader
          title="Opened vs Closed"
          subtitle={throughput_.data?.range_label}
          actions={<WidgetRange state={throughputRange} />}
        />
        <CardBody>
          <PanelState
            loading={throughput_.loading && !throughput_.data}
            empty={throughputSeries.length === 0}
            emptyMessage="No work was opened or closed in this range."
            height={300}
          >
            <div style={{ height: 300 }} className="relative">
              <BarChart options={throughput.options as any} data={throughput.data as any} />
            </div>
          </PanelState>
        </CardBody>
      </Card>

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
 * The precise meaning of each queue metric.
 *
 * Two of the four ignore the date range and two are bounded by it, which is a
 * real and confusing difference — "Open Work: 240" beside "Opened in Range: 12"
 * looks like an error until you know that the first counts every unresolved job
 * ever and the second only what arrived in the window. Written out rather than
 * implied, because a caption has room for a hint and not for a definition.
 */
const SLA_DEFINITIONS = {
  open_work: {
    title: 'Open Work',
    text: 'Total unresolved orders across every queue, regardless of when they were raised. Ignores the date range — a job opened four months ago is still open today.',
  },
  oldest_open: {
    title: 'Oldest Open',
    text: 'Days elapsed for the single longest-pending unresolved item, measured from when it was raised to today. Ignores the date range.',
  },
  opened_in_range: {
    title: 'Opened in Range',
    text: 'Orders created inside this widget’s date range, whether or not they have since been resolved.',
  },
  closed_in_range: {
    title: 'Closed in Range',
    text: 'Orders completed inside this widget’s date range, whatever date they were opened on.',
  },
};

/**
 * Wraps a metric card with a hoverable definition marker.
 *
 * The marker sits over the card rather than inside StatCard so that component
 * stays a plain presentational tile — it is used on five pages and only this one
 * needs the definitions.
 */
const MetricWithDefinition: React.FC<{
  definition: { title: string; text: string };
  children: React.ReactNode;
}> = ({ definition, children }) => {
  const isDarkMode = useTheme();
  const [open, setOpen] = React.useState(false);

  return (
    <div className="relative">
      {children}

      <button
        type="button"
        aria-label={`What ${definition.title} means`}
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => setOpen(false)}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onClick={() => setOpen((current) => !current)}
        className={`absolute top-2 right-2 rounded-full p-1 transition-colors ${
          isDarkMode ? 'text-gray-600 hover:text-gray-300' : 'text-gray-300 hover:text-gray-600'
        }`}
      >
        <HelpCircle size={14} />
      </button>

      {open && (
        <div
          role="tooltip"
          className={`absolute z-20 top-8 right-2 w-64 rounded-lg border p-2.5 text-xs shadow-lg ${
            isDarkMode
              ? 'bg-gray-900 border-gray-700 text-gray-300'
              : 'bg-white border-gray-200 text-gray-600'
          }`}
        >
          <p className={`font-semibold mb-1 ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
            {definition.title}
          </p>
          <p className="leading-snug">{definition.text}</p>
        </div>
      )}
    </div>
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
