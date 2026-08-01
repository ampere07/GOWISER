import React from 'react';
import { AlertTriangle, HardHat, MapPin, Radio, Timer } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import StatCard from '../components/reporting/StatCard';
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
  TotalRow,
  Tr,
} from '../components/reporting/primitives';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice, SourceCell } from '../components/reporting/DatabaseFilter';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { reportingService } from '../services/reportingService';
import { TechData, TechnicianLocation } from '../types/reporting';
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

  const { data, loading, error, sourceLabel, substituted } = useReportingSection<TechData>(
    reportingService.getTech,
    filters,
    refreshToken
  );

  const first = loading && !data;

  const workload = data?.workload ?? [];
  const busiest = workload.reduce((max, row) => Math.max(max, row.total), 0);

  const attributed = workload.reduce((sum, row) => sum + row.total, 0);
  const completed = workload.reduce((sum, row) => sum + row.completed, 0);
  const unattributed =
    (data?.unattributed.job_orders ?? 0) + (data?.unattributed.service_orders ?? 0);

  const live = (data?.locations ?? []).filter((location) => location.is_live);

  // Only in aggregate mode. It matters more here than elsewhere: two branches can
  // employ people with the same name, and the rows are deliberately not merged.
  const showSource = workload.some((row) => Boolean(row.source_label));

  return (
    <ReportingPage>
      <PageHeader
        title="Tech"
        subtitle={
          <>
            Technician roster and field workload
            {sourceLabel && <> · {sourceLabel}</>}
          </>
        }
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

      {/* ── Headline ──────────────────────────────────────────────────── */}
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
            data ? `of ${pluralise(data.locations.length, 'device')} reporting` : undefined
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
      <Card flush>
        <CardHeader
          title="Workload by Technician"
          subtitle={data?.range_label}
          badge={pluralise(workload.length, 'technician')}
        />
        <Table>
          <Thead>
            {showSource && <Th>Database</Th>}
            <Th>Technician</Th>
            <Th align="right">Job Orders</Th>
            <Th align="right">Service Orders</Th>
            <Th align="right">Total</Th>
            <Th align="right">Completed</Th>
            <Th align="right">Avg. on site</Th>
            <Th width="90px" />
          </Thead>
          <tbody>
            <TableState
              colSpan={showSource ? 8 : 7}
              loading={first}
              error={error}
              empty={workload.length === 0}
              emptyMessage="No technicians are on the roster in this system."
            />

            {workload.map((row) => (
              <Tr key={row.id}>
                {showSource && (
                  <Td>
                    <SourceCell label={row.source_label} />
                  </Td>
                )}
                <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {row.name}
                </Td>
                <Td align="right" className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                  {formatNumber(row.job_orders)}
                  <span className={`ml-1 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                    ({formatNumber(row.job_orders_done)} done)
                  </span>
                </Td>
                <Td align="right" className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                  {formatNumber(row.service_orders)}
                  <span className={`ml-1 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                    ({formatNumber(row.service_orders_done)} done)
                  </span>
                </Td>
                <Td align="right" className={`font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {formatNumber(row.total)}
                </Td>
                <Td align="right" className="text-emerald-600 dark:text-emerald-400 font-semibold">
                  {formatNumber(row.completed)}
                </Td>
                <Td align="right" className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                  {row.average_minutes === null ? '—' : `${row.average_minutes} min`}
                </Td>
                <Td>
                  <Bar pct={busiest > 0 ? (row.total / busiest) * 100 : 0} color="#0d6efd" />
                </Td>
              </Tr>
            ))}

            {workload.length > 0 && (
              <TotalRow>
                {showSource && <Td />}
                <Td>Total</Td>
                <Td align="right">
                  {formatNumber(workload.reduce((sum, row) => sum + row.job_orders, 0))}
                </Td>
                <Td align="right">
                  {formatNumber(workload.reduce((sum, row) => sum + row.service_orders, 0))}
                </Td>
                <Td align="right">{formatNumber(attributed)}</Td>
                <Td align="right" className="text-emerald-600 dark:text-emerald-400">
                  {formatNumber(completed)}
                </Td>
                <Td />
                <Td />
              </TotalRow>
            )}
          </tbody>
        </Table>
      </Card>

      {/* ── Field positions ──────────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="Field Positions"
          subtitle="Last reported location per technician device"
          icon={<MapPin size={16} />}
          actions={
            data && data.locations.length > 0 ? (
              <Pill tone={live.length > 0 ? 'success' : 'neutral'}>
                {live.length}/{data.locations.length} live
              </Pill>
            ) : undefined
          }
        />
        <CardBody>
          <PanelState
            loading={first}
            empty={(data?.locations.length ?? 0) === 0}
            emptyMessage="No technician devices have reported a position."
            height={160}
          >
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
              {(data?.locations ?? []).map((location) => (
                <LocationCard key={location.user_id} location={location} />
              ))}
            </div>
          </PanelState>
        </CardBody>
      </Card>

      {/* ── Roster ───────────────────────────────────────────────────── */}
      <Card flush>
        <CardHeader title="Roster" badge={pluralise(data?.roster.length ?? 0, 'technician')} />
        <Table>
          <Thead>
            <Th width="60px">#</Th>
            <Th>Name</Th>
            <Th>Middle initial</Th>
            <Th align="right">Record updated</Th>
          </Thead>
          <tbody>
            <TableState
              colSpan={4}
              loading={first}
              error={error}
              empty={(data?.roster.length ?? 0) === 0}
              emptyMessage="No technicians are registered."
            />

            {(data?.roster ?? []).map((technician, index) => (
              <Tr key={technician.id}>
                <Td className={isDarkMode ? 'text-gray-500' : 'text-gray-400'}>{index + 1}</Td>
                <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {technician.name}
                </Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {technician.initial || '—'}
                </Td>
                <Td align="right" className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {technician.updated_at ? formatTime(technician.updated_at) : '—'}
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Work is attributed by matching a technician's name against the assignment fields on each job,
        which this system records in three different shapes. The match is by name, so counts are
        indicative rather than exact — two technicians sharing a surname can both match one job. "In
        the field now" is derived from how recently a device reported, not from the status it claims.
      </p>
    </ReportingPage>
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
