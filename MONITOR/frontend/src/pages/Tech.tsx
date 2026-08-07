import React from 'react';
import { AlertTriangle, HardHat, MapPin, Radio, Timer } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import StatCard from '../components/reporting/StatCard';
import DataTable from '../components/reporting/DataTable';
import {
  Bar,
  ErrorBanner,
  PanelState,
  Pill,
  Td,
  TotalRow,
} from '../components/reporting/primitives';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice, SourceCell } from '../components/reporting/DatabaseFilter';
import { PageActions, PagePeriodBar, usePageChrome } from '../components/reporting/PageChrome';
import WidgetRange from '../components/reporting/WidgetRange';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { useLinkedRange } from '../hooks/useLinkedRange';
import { reportingService } from '../services/reportingService';
import { TechData, Technician, TechnicianLocation, TechnicianWorkload } from '../types/reporting';
import { formatNumber, formatTime, pluralise } from '../utils/format';

interface TechProps {
  refreshToken: number;
}

/**
 * Tech — the technician roster and its workload.
 *
 * Served from GOWISER, which is the only monitored system that records
 * technicians: a roster, per-job attribution, on-site timestamps and live field
 * positions. NETMANAGER has none of that, so opening this page while pointed at
 * NETMANAGER falls through to GOWISER and says so at the top.
 *
 * The honesty problem this page has to solve is attribution. GOWISER records who
 * did a job three different ways depending on which app wrote the row, and the
 * backend matches a technician's name against all three. That is a substring
 * match, so the counts are close but not exact — the unattributed panel and the
 * note at the foot say so rather than letting the table read as a precise ledger.
 */
const Tech: React.FC<TechProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { filters, update, reset, databases } = useSectionFilters('tech');

  const chrome = usePageChrome();
  const [reloads, setReloads] = React.useState(0);

  // One page period, which every widget follows until somebody moves it.
  // Headline counts and workload always move together because they are two
  // views of the same question — who did what, over what period — and splitting
  // them would let the summary contradict the table beneath it. Field positions
  // and the roster are statements of now and take a range only so the control is
  // consistent across the page.
  const pageRange = useWidgetRange('monthly');
  const workloadRange = useLinkedRange(pageRange);
  const positionsRange = useLinkedRange(pageRange);
  const rosterRange = useLinkedRange(pageRange);

  const primary = useReportingSection<TechData>(reportingService.getTech, filters, refreshToken + reloads, {
    dateFrom: workloadRange.range.from,
    dateTo: workloadRange.range.to,
  });

  const positions = useReportingSection<TechData>(reportingService.getTech, filters, refreshToken + reloads, {
    dateFrom: positionsRange.range.from,
    dateTo: positionsRange.range.to,
  });

  const roster = useReportingSection<TechData>(reportingService.getTech, filters, refreshToken + reloads, {
    dateFrom: rosterRange.range.from,
    dateTo: rosterRange.range.to,
  });

  const { data, loading, error, source, sourceLabel, substituted } = primary;

  const refresh = () => {
    reportingService.invalidate(source || undefined);
    setReloads((count) => count + 1);
  };

  const first = loading && !data;

  const workload = data?.workload ?? [];
  const busiest = workload.reduce((max, row) => Math.max(max, row.total), 0);

  const attributed = workload.reduce((sum, row) => sum + row.total, 0);
  const completed = workload.reduce((sum, row) => sum + row.completed, 0);
  const unattributed =
    (data?.unattributed.job_orders ?? 0) + (data?.unattributed.service_orders ?? 0);

  const live = (positions.data?.locations ?? []).filter((location) => location.is_live);

  // Only in aggregate mode. It matters more here than elsewhere: two branches can
  // employ people with the same name, and the rows are deliberately not merged.
  const showSource = workload.some((row) => Boolean(row.source_label));

  return (
    <div ref={chrome.container} className={chrome.containerClass}>
    <ReportingPage>
      <PageHeader
        title="Tech"
        subtitle={
          <>
            Technician roster and field workload
            {sourceLabel && <> · {sourceLabel}</>}
          </>
        }
        actions={<PageActions chrome={chrome} onRefresh={refresh} refreshing={loading} />}
      />

      <SourceNotice show={substituted} sourceLabel={sourceLabel} />
      <AggregateNotice aggregate={data?.aggregate} />
      {error && <ErrorBanner message={error} />}

      {/* No branch filter: technicians are not scoped to a branch in this schema. */}
      <FilterBar
        filters={filters}
        onChange={update}
        onReset={reset}
        branches={[]}
        databases={databases}
        showBranch={false}
      />

      <PagePeriodBar chrome={chrome} state={pageRange} />

      {/* ── Headline ──────────────────────────────────────────────────── */}
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
          <div className="min-w-0">
            <h3 className={`font-bold text-base ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
              Roster &amp; Workload
            </h3>
            <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              {data?.range_label ?? 'Selected range'}
            </p>
          </div>
          <WidgetRange state={workloadRange} />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Technicians"
          value={formatNumber(data?.roster_count)}
          tone="info"
          icon={<HardHat size={20} />}
          loading={first}
          caption="on the roster"
        />
        <StatCard
          label="In the field now"
          value={formatNumber(live.length)}
          tone={live.length > 0 ? 'success' : 'neutral'}
          icon={<Radio size={20} />}
          loading={first}
          caption={
            positions.data
              ? `of ${pluralise(positions.data.locations.length, 'device')} reporting`
              : undefined
          }
        />
        <StatCard
          label="Jobs attributed"
          value={formatNumber(attributed)}
          tone="neutral"
          icon={<Timer size={20} />}
          loading={first}
          caption={data?.range_label}
        />
        <StatCard
          label="Completed"
          value={formatNumber(completed)}
          tone="success"
          icon={<Timer size={20} />}
          loading={first}
          caption={
            attributed > 0
              ? `${Math.round((completed / attributed) * 100)}% of attributed work`
              : 'nothing attributed in this range'
          }
        />
        </div>
      </Card>

      {/* Unattributed work is the number that says the table below is partial.
          Surfaced, not hidden, because a partial table read as complete is worse
          than a visible gap. */}
      {unattributed > 0 && (
        <div
          className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
            isDarkMode
              ? 'border-amber-800/60 bg-amber-500/10 text-amber-200'
              : 'border-amber-200 bg-amber-50 text-amber-800'
          }`}
        >
          <AlertTriangle size={15} className="mt-0.5 flex-shrink-0" />
          <span>
            {pluralise(unattributed, 'job')} in this range has nobody recorded against it
            {data && (
              <>
                {' '}
                ({formatNumber(data.unattributed.job_orders)} job order
                {data.unattributed.job_orders === 1 ? '' : 's'},{' '}
                {formatNumber(data.unattributed.service_orders)} service order
                {data.unattributed.service_orders === 1 ? '' : 's'})
              </>
            )}
            , so the per-technician figures below do not add up to the totals above.
          </span>
        </div>
      )}

      {/* ── Workload ──────────────────────────────────────────────────── */}
      <DataTable<TechnicianWorkload>
        title="Workload by Technician"
        subtitle={data?.range_label}
        badge={pluralise(workload.length, 'technician')}
        actions={<WidgetRange state={workloadRange} />}
        rows={workload}
        rowKey={(row) => row.id}
        loading={first}
        error={error}
        emptyMessage="No technicians are on the roster in this system."
        searchPlaceholder="Search technician…"
        defaultSort="total"
        columns={[
          // Only in aggregate mode. It matters more here than elsewhere: two
          // branches can employ people with the same name, and the rows are
          // deliberately not merged.
          ...(showSource
            ? [
                {
                  key: 'source',
                  header: 'Database',
                  value: (row: TechnicianWorkload) => row.source_label ?? '',
                  render: (row: TechnicianWorkload) => <SourceCell label={row.source_label} />,
                },
              ]
            : []),
          {
            key: 'designated_area',
            header: 'Designated Area / Barangay',
            value: (row: TechnicianWorkload) => (row as any).designated_area ?? 'Field Designated Area',
            render: (row: TechnicianWorkload) => (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300">
                <MapPin size={12} className="text-indigo-500" />
                {(row as any).designated_area || 'Field Designated Area'}
              </span>
            ),
          },
          {
            key: 'name',
            header: 'Technician',
            value: (row) => row.name,
            render: (row) => (
              <span className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {row.name}
              </span>
            ),
          },
          {
            key: 'job_orders',
            header: 'Job Orders',
            align: 'right',
            value: (row) => row.job_orders,
            searchable: false,
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                {formatNumber(row.job_orders)}
                <span className={`ml-1 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                  ({formatNumber(row.job_orders_done)} done)
                </span>
              </span>
            ),
          },
          {
            key: 'service_orders',
            header: 'Service Orders',
            align: 'right',
            value: (row) => row.service_orders,
            searchable: false,
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                {formatNumber(row.service_orders)}
                <span className={`ml-1 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                  ({formatNumber(row.service_orders_done)} done)
                </span>
              </span>
            ),
          },
          {
            key: 'total',
            header: 'Total',
            align: 'right',
            value: (row) => row.total,
            searchable: false,
            render: (row) => (
              <span className={`font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {formatNumber(row.total)}
              </span>
            ),
          },
          {
            key: 'completed',
            header: 'Completed',
            align: 'right',
            value: (row) => row.completed,
            searchable: false,
            render: (row) => (
              <span className="text-emerald-600 dark:text-emerald-400 font-semibold">
                {formatNumber(row.completed)}
              </span>
            ),
          },
          {
            key: 'average',
            header: 'Avg. on site',
            align: 'right',
            // Nulls sink in DataTable regardless of direction, so technicians
            // with no timed work do not head a "slowest first" sort.
            value: (row) => row.average_minutes,
            searchable: false,
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                {row.average_minutes === null ? '—' : `${row.average_minutes} min`}
              </span>
            ),
          },
          {
            key: 'bar',
            header: '',
            width: '90px',
            render: (row) => (
              <Bar pct={busiest > 0 ? (row.total / busiest) * 100 : 0} color="#0d6efd" />
            ),
          },
        ]}
        footer={(visible) => (
          <TotalRow>
            {showSource && <Td />}
            <Td>Total</Td>
            <Td align="right">
              {formatNumber(visible.reduce((sum, row) => sum + row.job_orders, 0))}
            </Td>
            <Td align="right">
              {formatNumber(visible.reduce((sum, row) => sum + row.service_orders, 0))}
            </Td>
            <Td align="right">{formatNumber(visible.reduce((sum, row) => sum + row.total, 0))}</Td>
            <Td align="right" className="text-emerald-600 dark:text-emerald-400">
              {formatNumber(visible.reduce((sum, row) => sum + row.completed, 0))}
            </Td>
            <Td />
            <Td />
          </TotalRow>
        )}
      />

      {/* ── Field positions ──────────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="Field Positions"
          subtitle="Last reported location per technician device"
          icon={<MapPin size={16} />}
          actions={
            <div className="flex flex-wrap items-center gap-2 justify-end">
              {positions.data && positions.data.locations.length > 0 && (
                <Pill tone={live.length > 0 ? 'success' : 'neutral'}>
                  {live.length}/{positions.data.locations.length} live
                </Pill>
              )}
              <WidgetRange state={positionsRange} />
            </div>
          }
        />
        <CardBody>
          <PanelState
            loading={positions.loading && !positions.data}
            empty={(positions.data?.locations.length ?? 0) === 0}
            emptyMessage="No technician devices have reported a position."
            height={160}
          >
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
              {(positions.data?.locations ?? []).map((location) => (
                <LocationCard key={location.user_id} location={location} />
              ))}
            </div>
          </PanelState>
        </CardBody>
      </Card>

      {/* ── Roster ───────────────────────────────────────────────────── */}
      <DataTable<Technician>
        title="Roster"
        badge={pluralise(roster.data?.roster.length ?? 0, 'technician')}
        rows={roster.data?.roster ?? []}
        rowKey={(technician) => technician.id}
        loading={roster.loading && !roster.data}
        error={roster.error}
        emptyMessage="No technicians are registered."
        searchPlaceholder="Search name…"
        defaultSort="name"
        defaultDescending={false}
        columns={[
          {
            key: 'index',
            header: '#',
            width: '60px',
            // Positional, so it renumbers with the sort rather than following
            // rows around — a row-order marker, not an identifier.
            render: (_technician, index) => (
              <span className={isDarkMode ? 'text-gray-500' : 'text-gray-400'}>{index + 1}</span>
            ),
          },
          {
            key: 'name',
            header: 'Name',
            value: (technician) => technician.name,
            render: (technician) => (
              <span className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {technician.name}
              </span>
            ),
          },
          {
            key: 'initial',
            header: 'Middle initial',
            value: (technician) => technician.initial,
            render: (technician) => (
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {technician.initial || '—'}
              </span>
            ),
          },
          {
            key: 'updated',
            header: 'Record updated',
            align: 'right',
            value: (technician) => technician.updated_at ?? '',
            searchable: false,
            render: (technician) => (
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {technician.updated_at ? formatTime(technician.updated_at) : '—'}
              </span>
            ),
          },
        ]}
      />

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Work is attributed by matching a technician's name against the assignment fields on each job,
        which this system records in three different shapes. The match is by name, so counts are
        indicative rather than exact — two technicians sharing a surname can both match one job. "In
        the field now" is derived from how recently a device reported, not from the status it claims.
      </p>
    </ReportingPage>
    </div>
  );
};

/**
 * One technician's last known position.
 *
 * Shows minutes-since rather than a timestamp as the primary signal: "4 min ago"
 * answers "is this current?" and a clock time does not.
 */
const LocationCard: React.FC<{ location: TechnicianLocation }> = ({ location }) => {
  const isDarkMode = useTheme();

  const coordinates =
    location.latitude !== null && location.longitude !== null
      ? `${location.latitude.toFixed(5)}, ${location.longitude.toFixed(5)}`
      : null;

  return (
    <div
      className={`rounded-lg border p-3 ${
        location.is_live
          ? isDarkMode
            ? 'border-emerald-800/60 bg-gray-900'
            : 'border-emerald-300 bg-white'
          : isDarkMode
          ? 'border-gray-800 bg-gray-900'
          : 'border-gray-200 bg-white'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <p className={`font-semibold truncate ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
          {location.name}
        </p>
        <Pill tone={location.is_live ? 'success' : 'neutral'}>
          {location.is_live ? 'Live' : 'Stale'}
        </Pill>
      </div>

      <p className={`mt-1 text-xs font-mono ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        {coordinates ?? 'no fix'}
      </p>

      <div className={`mt-2 flex items-center gap-3 text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        <span>
          {location.minutes_ago === null
            ? 'never reported'
            : location.minutes_ago === 0
            ? 'just now'
            : `${formatNumber(location.minutes_ago)} min ago`}
        </span>
        {location.accuracy_m !== null && <span>±{Math.round(location.accuracy_m)} m</span>}
      </div>
    </div>
  );
};

export default Tech;
