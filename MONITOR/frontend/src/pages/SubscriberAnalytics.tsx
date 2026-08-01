import React from 'react';
import { Hourglass, UserCheck, UserMinus, UserPlus, UserX, Wifi } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import StatCard from '../components/reporting/StatCard';
import TopBarangaysPanel from '../components/reporting/TopBarangaysPanel';
import OverdueAccountsPanel from '../components/reporting/OverdueAccountsPanel';
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

/** Status colours, so the same status is the same colour everywhere. */
const STATUS_COLORS: Record<string, string> = {
  active: '#198754',
  expired: '#dc3545',
  overdue: '#dc3545',
  suspended: '#ffc107',
  pending: '#6c757d',
  'in progress': '#6c757d',
  cancelled: '#adb5bd',
};

const statusColor = (label: string): string =>
  STATUS_COLORS[label.toLowerCase().trim()] ?? '#adb5bd';

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
  const { filters, update, reset, branches, databases } = useSectionFilters('subscriber_analytics');

  const { data, loading, error, sourceLabel, substituted } =
    useReportingSection<SubscriberAnalyticsData>(
      reportingService.getSubscriberAnalytics,
      filters,
      refreshToken
    );

  const kpi = data?.kpi;
  const first = loading && !data;

  // Every status the data holds, largest first — including ones this app has
  // never seen, so a new workflow state cannot vanish from the chart.
  const statuses = Object.entries(data?.status.by_status ?? {})
    .filter(([, count]) => count > 0)
    .sort(([, a], [, b]) => b - a);

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

      <FilterBar
        filters={filters}
        onChange={update}
        onReset={reset}
        branches={branches}
        databases={databases}
        showBranch={branches.length > 0}
      />

      {/* ── Status pipeline ───────────────────────────────────────────── */}
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
          label="Pending"
          value={formatNumber(kpi?.pending)}
          tone="neutral"
          icon={<Hourglass size={20} />}
          loading={first}
          caption="awaiting activation"
        />
        <StatCard
          label="Suspended"
          value={formatNumber(kpi?.suspended)}
          tone="warning"
          icon={<UserMinus size={20} />}
          loading={first}
          caption="on hold"
        />
        <StatCard
          label="Expired"
          value={formatNumber(kpi?.expired)}
          tone="danger"
          icon={<UserX size={20} />}
          loading={first}
          caption="needs renewal"
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
                labels={statuses.map(([label]) => label.charAt(0).toUpperCase() + label.slice(1))}
                values={statuses.map(([, count]) => count)}
                colors={statuses.map(([label]) => statusColor(label))}
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
      <TopBarangaysPanel rows={data?.top_barangays ?? []} loading={first} error={error} />

      {/* ── Who owes ──────────────────────────────────────────────────── */}
      <OverdueAccountsPanel
        ledger={data?.overdue ?? null}
        loading={first}
        error={error}
        onApply={({ search, planId, bucket }) =>
          update({
            overdueSearch: search,
            overduePlanId: planId,
            overdueBucket: bucket,
            // Filters change how many pages exist, so page 4 of the old result
            // is meaningless against the new one.
            overduePage: 1,
          })
        }
        onClear={() =>
          update({ overdueSearch: '', overduePlanId: 0, overdueBucket: '', overduePage: 1 })
        }
        onPageChange={(page) => update({ overduePage: page })}
      />

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Counts are as of {data?.as_of ?? 'today'} and are not bounded by the date range — the range
        applies to "New in range" and nothing else. {pluralise(data?.overdue.total ?? 0, 'account')} carry
        a balance or a lapsed subscription.
      </p>
    </ReportingPage>
  );
};

export default SubscriberAnalytics;
