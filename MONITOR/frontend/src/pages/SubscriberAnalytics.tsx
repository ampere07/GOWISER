import React from 'react';
import { Crown, Hourglass, PackageX, UserCheck, UserMinus, UserPlus, UserX, Wifi } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import StatCard from '../components/reporting/StatCard';
import BarangayTable from '../components/reporting/BarangayTable';
import OverdueAccountsPanel from '../components/reporting/OverdueAccountsPanel';
import WidgetRange from '../components/reporting/WidgetRange';
import { DonutChart } from '../components/reporting/charts';
import { ErrorBanner, PanelState } from '../components/reporting/primitives';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice } from '../components/reporting/DatabaseFilter';
import { Restricted, RestrictedPanel } from '../components/rbac/Restricted';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { reportingService } from '../services/reportingService';
import { SubscriberAnalyticsData } from '../types/reporting';
import { WIDGET } from '../types/rbac';
import { formatMoney, formatNumber, pluralise } from '../utils/format';

interface SubscriberAnalyticsProps {
  refreshToken: number;
}

/**
 * Status colours, so the same status is the same colour everywhere.
 *
 * Keyed on the *reported* labels, not the source systems' raw values. The
 * backend maps Suspended to Restricted and Expired to Disconnected before the
 * payload leaves (see StatusMap), so matching on the old words here would silently
 * fall through to grey for the two statuses that most need to stand out.
 */
const STATUS_COLORS: Record<string, string> = {
  active: '#198754',
  vip: '#7c3aed',
  restricted: '#ffc107',
  disconnected: '#dc3545',
  inactive: '#6c757d',
  pullout: '#d63384',
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
 * rather than of a period's collections — and which follows the revenue
 * permission rather than this module's, for the same reason.
 *
 * Three things this page no longer does, all of them deliberate: it does not
 * count pending applications as subscribers, it does not cap the barangay table
 * at ten rows, and it does not carry a page-level period filter — each widget
 * below owns its own range.
 */
const SubscriberAnalytics: React.FC<SubscriberAnalyticsProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { filters, update, reset, branches, databases } = useSectionFilters('subscriber_analytics');

  // Each widget's own window. The status and plan charts are statements of the
  // base as it stands and are not bounded by a range at all, but growth and the
  // barangay table are — so each gets its own control rather than one for the page.
  const growthRange = useWidgetRange('monthly');
  const compositionRange = useWidgetRange('monthly');
  const barangayRange = useWidgetRange('monthly');

  const primary = useReportingSection<SubscriberAnalyticsData>(
    reportingService.getSubscriberAnalytics,
    filters,
    refreshToken,
    { dateFrom: growthRange.range.from, dateTo: growthRange.range.to }
  );

  const composition = useReportingSection<SubscriberAnalyticsData>(
    reportingService.getSubscriberAnalytics,
    filters,
    refreshToken,
    { dateFrom: compositionRange.range.from, dateTo: compositionRange.range.to }
  );

  const geography = useReportingSection<SubscriberAnalyticsData>(
    reportingService.getSubscriberAnalytics,
    filters,
    refreshToken,
    { dateFrom: barangayRange.range.from, dateTo: barangayRange.range.to }
  );

  const { data, loading, error, sourceLabel, substituted } = primary;

  const kpi = data?.kpi;
  const billing = data?.billing_summary;
  const first = loading && !data;

  // Every reported status, largest first — including ones this app has never
  // seen, so a new workflow state cannot vanish from the chart.
  const statuses = Object.entries(composition.data?.status.by_status ?? {})
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

      {/* ── Billing status summary header ─────────────────────────────── */}
      <Restricted
        require={WIDGET.subscriberBilling}
        fallback={<RestrictedPanel title="Billing Status Summary" height={120} />}
      >
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard
            label="Active"
            value={formatNumber(billing?.active)}
            tone="success"
            icon={<UserCheck size={20} />}
            loading={first}
            caption={
              billing ? (
                <>
                  of <span className="font-semibold">{formatNumber(billing.total)}</span> billed
                  accounts
                </>
              ) : undefined
            }
          />
          <StatCard
            label="VIP"
            value={formatNumber(billing?.vip)}
            tone="info"
            icon={<Crown size={20} />}
            loading={first}
            caption="priority accounts"
          />
          <StatCard
            label="Inactive"
            value={formatNumber(billing?.inactive)}
            tone="neutral"
            icon={<UserMinus size={20} />}
            loading={first}
            caption="closed or cancelled"
          />
          <StatCard
            label="Pullout"
            value={formatNumber(billing?.pullout)}
            tone="danger"
            icon={<PackageX size={20} />}
            loading={first}
            caption="equipment recovered"
          />
        </div>
      </Restricted>

      {/* ── Service state ─────────────────────────────────────────────── */}
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
                Subscribers: <span className="font-semibold">{formatNumber(kpi.total)}</span>
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
          caption="priority service"
        />
        {/* Was "Suspended". Renamed at the source of the data, not just in this
            label, so the chart, the table and the card cannot disagree. */}
        <StatCard
          label="Restricted"
          value={formatNumber(kpi?.restricted)}
          tone="warning"
          icon={<UserMinus size={20} />}
          loading={first}
          caption="service limited"
        />
        {/* Was "Expired". */}
        <StatCard
          label="Disconnected"
          value={formatNumber(kpi?.disconnected)}
          tone="danger"
          icon={<UserX size={20} />}
          loading={first}
          caption="service lapsed"
        />
      </div>

      {/* ── Runway and growth ─────────────────────────────────────────── */}
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
          <div className="min-w-0">
            <h3 className={`font-bold text-base ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
              Runway &amp; Growth
            </h3>
            <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              New subscribers over {data?.range_label ?? 'the selected range'}
            </p>
          </div>
          <WidgetRange state={growthRange} />
        </div>

        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Counted from the prepaid service window, so the caption says
              prepaid: postpaid accounts have no expiry to run out and are
              excluded. Still null-guarded for any schema that tracks no expiry,
              where "not tracked" is the honest answer rather than a zero that
              reads as "none expiring". */}
          <StatCard
            label="Expiring in 3 days"
            value={kpi?.expiring_3day === null ? 'Not tracked' : formatNumber(kpi?.expiring_3day)}
            tone="warning"
            icon={<Hourglass size={18} />}
            loading={first}
            caption={
              kpi?.expiring_3day === null ? 'no expiry date in this system' : 'active prepaid accounts'
            }
          />
          <StatCard
            label="Expiring in 7 days"
            value={kpi?.expiring_7day === null ? 'Not tracked' : formatNumber(kpi?.expiring_7day)}
            tone="warning"
            icon={<Hourglass size={18} />}
            loading={first}
            caption={
              kpi?.expiring_7day === null ? 'no expiry date in this system' : 'active prepaid accounts'
            }
          />
          <StatCard
            label="New in range"
            value={formatNumber(data?.growth.new_in_range)}
            tone="info"
            icon={<UserPlus size={18} />}
            loading={first}
            caption={data?.range_label}
          />
          {/* A revenue figure on a counting page, so it follows the revenue
              permission rather than this module's. */}
          <Restricted
            require={WIDGET.financialRevenue}
            fallback={<RestrictedPanel title="Expected MRC" height={110} />}
          >
            <StatCard
              label="Expected MRC"
              value={
                data?.growth.expected_mrc === null
                  ? 'Not available'
                  : formatMoney(data?.growth.expected_mrc)
              }
              tone="success"
              icon={<UserCheck size={18} />}
              loading={first}
              caption="active base, at plan price"
            />
          </Restricted>
        </div>
      </Card>

      {/* ── Composition ───────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card flush>
          <CardHeader
            title="Subscriber Status"
            subtitle={composition.data?.branch_label}
            actions={<WidgetRange state={compositionRange} />}
          />
          <CardBody>
            <PanelState
              loading={composition.loading && !composition.data}
              empty={statuses.length === 0}
              emptyMessage="No subscribers on file."
              height={300}
            >
              {/* Counts are drawn on the slices — see sliceValuePlugin. A pie
                  whose numbers live only in a tooltip cannot be read on the wall
                  display these are shown on. */}
              <DonutChart
                labels={statuses.map(([label]) => label)}
                values={statuses.map(([, count]) => count)}
                colors={statuses.map(([label]) => statusColor(label))}
                unit="count"
                height={300}
              />
            </PanelState>

            {(composition.data?.status.excluded ?? 0) > 0 && (
              <p className={`mt-2 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                {pluralise(composition.data?.status.excluded ?? 0, 'pending application')} excluded —
                an application that has not been activated is not a subscriber.
              </p>
            )}
          </CardBody>
        </Card>

        <Card flush>
          <CardHeader
            title="Active Subscribers by Plan"
            subtitle={composition.data?.branch_label}
            actions={<WidgetRange state={compositionRange} />}
          />
          <CardBody>
            <PanelState
              loading={composition.loading && !composition.data}
              empty={(composition.data?.plans.length ?? 0) === 0}
              emptyMessage="No active subscribers on any plan."
              height={300}
            >
              <DonutChart
                labels={(composition.data?.plans ?? []).map((plan) => plan.label)}
                values={(composition.data?.plans ?? []).map((plan) => plan.count)}
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

      {/* ── Geography: every barangay, not a top ten ───────────────────── */}
      <Restricted
        require={WIDGET.subscriberBarangay}
        fallback={<RestrictedPanel title="Barangay Breakdown" height={240} />}
      >
        <BarangayTable
          rows={geography.data?.barangays ?? []}
          loading={geography.loading && !geography.data}
          error={geography.error}
          actions={<WidgetRange state={barangayRange} />}
        />
      </Restricted>

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
        Counts are as of {data?.as_of ?? 'today'}. Pending applications are excluded throughout —
        they are not subscribers. <strong>Restricted</strong> is what the operating systems record as
        suspended, and <strong>Disconnected</strong> what they record as expired or overdue.{' '}
        {pluralise(data?.overdue.total ?? 0, 'account')} carry a balance or a lapsed subscription.
      </p>
    </ReportingPage>
  );
};

export default SubscriberAnalytics;
