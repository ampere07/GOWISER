import React from 'react';
import { Crown, Hourglass, PackageX, UserCheck, UserMinus, UserPlus, Wifi } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import StatCard from '../components/reporting/StatCard';
import BarangayAnalyticsPanel from '../components/reporting/BarangayAnalyticsPanel';
import { DonutChart } from '../components/reporting/charts';
import { ErrorBanner, PanelState } from '../components/reporting/primitives';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice } from '../components/reporting/DatabaseFilter';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { reportingService } from '../services/reportingService';
import { SubscriberAnalyticsData } from '../types/reporting';
import { formatMoney, formatNumber, pluralise } from '../utils/format';

interface SubscriberAnalyticsProps {
  refreshToken: number;
}

/**
 * How a stored status is presented.
 *
 * The source systems keep their own vocabulary — GOWISER records a RADIUS restriction as
 * "Suspended" and a disconnection as "Overdue" — while the business reads them as Restricted and
 * Disconnected. Renamed here rather than in the database, which MONITOR only ever reads.
 *
 * A status mapped to `null` is dropped from the chart entirely. Pending is dropped because an
 * account awaiting activation is not part of the subscriber base being reported on.
 */
const STATUS_DISPLAY: Record<string, { label: string; color: string } | null> = {
  active: { label: 'Active', color: '#198754' },
  vip: { label: 'VIP', color: '#8b5cf6' },
  inactive: { label: 'Inactive', color: '#f59e0b' },
  pullout: { label: 'Pullout', color: '#dc3545' },
  suspended: { label: 'Restricted', color: '#ffc107' },
  restricted: { label: 'Restricted', color: '#ffc107' },
  overdue: { label: 'Disconnected', color: '#b02a37' },
  expired: { label: 'Disconnected', color: '#b02a37' },
  disconnected: { label: 'Disconnected', color: '#b02a37' },
  cancelled: { label: 'Cancelled', color: '#adb5bd' },
  pending: null,
  'in progress': null,
};

/**
 * Statuses for the chart: renamed, with Pending removed, and same-named ones summed.
 *
 * Summing matters — Suspended and Restricted both display as "Restricted", and a source holding
 * both would otherwise draw two slices with the same name. A status this app has never seen is
 * passed through under its own name rather than dropped, so a new workflow state stays visible.
 */
const chartStatuses = (byStatus: Record<string, number>): Array<[string, number, string]> => {
  const merged: Record<string, { count: number; color: string }> = {};

  Object.entries(byStatus).forEach(([raw, count]) => {
    if (count <= 0) return;

    const key = raw.toLowerCase().trim();
    if (key in STATUS_DISPLAY && STATUS_DISPLAY[key] === null) return;

    const display = STATUS_DISPLAY[key] ?? {
      label: raw.charAt(0).toUpperCase() + raw.slice(1),
      color: '#adb5bd',
    };

    const existing = merged[display.label];
    merged[display.label] = {
      count: (existing?.count ?? 0) + count,
      color: existing?.color ?? display.color,
    };
  });

  return Object.entries(merged)
    .map(([label, { count, color }]): [string, number, string] => [label, count, color])
    .sort((a, b) => b[1] - a[1]);
};

/**
 * Subscriber Analytics — who the subscribers are, and which of them are a
 * problem.
 *
 * Deliberately not a money page: it counts people and accounts. The one currency
 * figure is expected MRC, which is here because it is a property of the base
 * rather than of a period's collections.
 */
const SubscriberAnalytics: React.FC<SubscriberAnalyticsProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  // Filters are still resolved because the section request needs a source and branch scope; the
  // page no longer offers a period control, since every figure on it is a snapshot of the base
  // as it stands rather than something that accrues over a window.
  const { filters } = useSectionFilters('subscriber_analytics');

  const { data, loading, error, sourceLabel, substituted } =
    useReportingSection<SubscriberAnalyticsData>(
      reportingService.getSubscriberAnalytics,
      filters,
      refreshToken
    );

  const kpi = data?.kpi;
  const first = loading && !data;

  const statuses = chartStatuses(data?.status.by_status ?? {});

  return (
    <ReportingPage>
      <PageHeader
        title="Subscriber Analytics"
        subtitle={
          <>
            The subscriber base as it stands
            {sourceLabel && <> · {sourceLabel}</>}
            {data && data.branch_label !== 'All branches' && <> · {data.branch_label}</>}
          </>
        }
      />

      <SourceNotice show={substituted} sourceLabel={sourceLabel} />
      <AggregateNotice aggregate={data?.aggregate} />
      {error && <ErrorBanner message={error} />}

      {/* ── The four subscriber categories ────────────────────────────── */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Active"
          value={formatNumber(kpi?.active)}
          tone="success"
          icon={<UserCheck size={20} />}
          loading={first}
          caption={
            kpi ? (
              <>
                Total: <span className="font-semibold">{formatNumber(kpi.total)}</span>
              </>
            ) : undefined
          }
        />
        <StatCard
          label="VIP"
          value={formatNumber(kpi?.vip)}
          tone="info"
          icon={<Crown size={20} />}
          loading={first}
          caption="comped accounts"
        />
        <StatCard
          label="Inactive"
          value={formatNumber(kpi?.inactive)}
          tone="warning"
          icon={<UserMinus size={20} />}
          loading={first}
          caption="not in service"
        />
        <StatCard
          label="Pullout"
          value={formatNumber(kpi?.pullout)}
          tone="danger"
          icon={<PackageX size={20} />}
          loading={first}
          caption="equipment recovered"
        />
      </div>

      {/* ── Runway and growth ─────────────────────────────────────────── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Counted from the prepaid service window, so the caption says prepaid:
            postpaid accounts have no expiry to run out and are excluded. Still
            null-guarded for any schema that tracks no expiry at all, where "not
            tracked" is the honest answer rather than a zero that reads as "none
            expiring". */}
        <StatCard
          label="Expiring in 3 days"
          value={kpi?.expiring_3day === null ? 'Not tracked' : formatNumber(kpi?.expiring_3day)}
          tone="warning"
          icon={<Hourglass size={18} />}
          loading={first}
          caption={kpi?.expiring_3day === null ? 'no expiry date in this system' : 'active prepaid accounts'}
        />
        <StatCard
          label="Expiring in 7 days"
          value={kpi?.expiring_7day === null ? 'Not tracked' : formatNumber(kpi?.expiring_7day)}
          tone="warning"
          icon={<Hourglass size={18} />}
          loading={first}
          caption={kpi?.expiring_7day === null ? 'no expiry date in this system' : 'active prepaid accounts'}
        />
        <StatCard
          label="New in range"
          value={formatNumber(data?.growth.new_in_range)}
          tone="info"
          icon={<UserPlus size={18} />}
          loading={first}
          caption={data?.range_label}
        />
        <StatCard
          label="Expected MRC"
          value={
            data?.growth.expected_mrc === null ? 'Not available' : formatMoney(data?.growth.expected_mrc)
          }
          tone="success"
          icon={<UserCheck size={18} />}
          loading={first}
          caption="active base, at plan price"
        />
      </div>

      {/* ── Composition ───────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card flush>
          <CardHeader title="Subscriber Status" subtitle={data?.branch_label} />
          <CardBody>
            <PanelState
              loading={first}
              empty={statuses.length === 0}
              emptyMessage="No subscribers on file."
              height={300}
            >
              <DonutChart
                labels={statuses.map(([label]) => label)}
                values={statuses.map(([, count]) => count)}
                colors={statuses.map(([, , color]) => color)}
                unit="count"
                height={300}
              />
            </PanelState>
          </CardBody>
        </Card>

        <Card flush>
          <CardHeader title="Active Subscribers by Plan" subtitle={data?.branch_label} />
          <CardBody>
            <PanelState
              loading={first}
              empty={(data?.plans.length ?? 0) === 0}
              emptyMessage="No active subscribers on any plan."
              height={300}
            >
              <DonutChart
                labels={(data?.plans ?? []).map((plan) => plan.label)}
                values={(data?.plans ?? []).map((plan) => plan.count)}
                unit="count"
                height={300}
              />
            </PanelState>
          </CardBody>
        </Card>
      </div>

      {/* ── Network presence, where the source records it ──────────────── */}
      {data?.sessions && data.sessions.length > 0 && (
        <Card flush>
          <CardHeader
            title="Session Status"
            subtitle="Live connection state, as last recorded"
            icon={<Wifi size={16} />}
          />
          <CardBody>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              {data.sessions.map((session) => (
                <div
                  key={session.label}
                  className={`rounded-lg px-3 py-2 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
                >
                  <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                    {session.label}
                  </p>
                  <p className="text-xl font-bold">{formatNumber(session.count)}</p>
                </div>
              ))}
            </div>
          </CardBody>
        </Card>
      )}

      {/* ── Geography ─────────────────────────────────────────────────── */}
      <BarangayAnalyticsPanel rows={data?.top_barangays ?? []} loading={first} error={error} />

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Counts are as of {data?.as_of ?? 'today'} — a snapshot of the base as it stands, not a
        figure that accrues over a period. Covering {pluralise(data?.status.total ?? 0, 'account')}.
      </p>
    </ReportingPage>
  );
};

export default SubscriberAnalytics;
