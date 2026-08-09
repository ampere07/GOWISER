import React from 'react';
import {
  Crown,
  Hourglass,
  PackageX,
  UserCheck,
  UserMinus,
  UserPlus,
  Users,
  UserX,
  Wifi,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import StatCard, { Tone } from '../components/reporting/StatCard';
import BarangayTable from '../components/reporting/BarangayTable';
import WidgetRange from '../components/reporting/WidgetRange';
import { DonutChart } from '../components/reporting/charts';
import { ErrorBanner, PanelState } from '../components/reporting/primitives';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice } from '../components/reporting/DatabaseFilter';
import { PageActions, PagePeriodBar, usePageChrome } from '../components/reporting/PageChrome';
import { Restricted, RestrictedPanel } from '../components/rbac/Restricted';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { useLinkedRange } from '../hooks/useLinkedRange';
import { reportingService } from '../services/reportingService';
import { SubscriberAnalyticsData } from '../types/reporting';
import { WIDGET } from '../types/rbac';
import { formatNumber, pluralise } from '../utils/format';

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
 * Card tone and icon per status.
 *
 * Looked up rather than positional, because the cards are now whatever SYNC
 * holds and in whatever order — a status this app has never seen still has to
 * render, so both fall back rather than throwing or blanking.
 */
const STATUS_TONES: Record<string, Tone> = {
  active: 'success',
  vip: 'info',
  restricted: 'warning',
  disconnected: 'danger',
  inactive: 'neutral',
  pullout: 'danger',
};

const STATUS_ICONS: Record<string, React.ReactNode> = {
  active: <UserCheck size={20} />,
  vip: <Crown size={20} />,
  restricted: <UserMinus size={20} />,
  disconnected: <UserX size={20} />,
  inactive: <UserMinus size={20} />,
  pullout: <PackageX size={20} />,
};

/** One line of context per status, so a bare count is not left to interpretation. */
const STATUS_CAPTIONS: Record<string, string> = {
  active: 'billed and in service',
  vip: 'priority accounts',
  restricted: 'service limited',
  disconnected: 'service lapsed',
  inactive: 'closed or cancelled',
  pullout: 'equipment recovered',
};

const statusTone = (label: string): Tone =>
  STATUS_TONES[label.toLowerCase().trim()] ?? 'neutral';

const statusIcon = (label: string): React.ReactNode =>
  STATUS_ICONS[label.toLowerCase().trim()] ?? <Users size={20} />;

/**
 * Subscriber Analytics — who the subscribers are, and which of them are a
 * problem.
 *
 * Not a money page at all: it counts people and accounts, and every figure on it
 * is a headcount. Expected MRC was the one exception and has been withdrawn —
 * see the note above the growth cards.
 *
 * Three things this page no longer does, all of them deliberate: it does not
 * count pending applications as subscribers, it does not cap the barangay table
 * at ten rows, and it does not carry a page-level period filter — each widget
 * below owns its own range.
 */
const SubscriberAnalytics: React.FC<SubscriberAnalyticsProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  // Filters are still resolved because the section request needs a source and branch scope; the
  // page no longer offers a period control, since every figure on it is a snapshot of the base
  // as it stands rather than something that accrues over a window.
  const { filters, update, reset, branches, databases } = useSectionFilters('subscriber_analytics');

  const chrome = usePageChrome();
  const [reloads, setReloads] = React.useState(0);

  // Only one widget on this page is period-bound. The billing summary, the
  // status and plan charts and the barangay table are all statements of the base
  // *as it stands* — filtering a headcount to a date window does not narrow it,
  // it just makes the number wrong — so they carry no range control at all.
  //
  // Which is why the period bar above carries a note saying so. A page-wide
  // control that visibly moves one card in five would otherwise read as broken.
  //
  // Opens on Daily rather than month-to-date. "How many joined today" is the
  // question this page is opened for; month-to-date is the one asked afterwards,
  // and it is one click away.
  const pageRange = useWidgetRange('daily');
  const growthRange = useLinkedRange(pageRange);

  const { data, loading, error, source, sourceLabel, substituted } =
    useReportingSection<SubscriberAnalyticsData>(
      reportingService.getSubscriberAnalytics,
      filters,
      refreshToken + reloads,
      { dateFrom: growthRange.range.from, dateTo: growthRange.range.to }
    );

  const refresh = () => {
    reportingService.invalidate(source || undefined);
    setReloads((count) => count + 1);
  };

  const kpi = data?.kpi;
  const billing = data?.billing_summary;
  const first = loading && !data;

  // Every reported status, largest first — including ones this app has never
  // seen, so a new workflow state cannot vanish from the chart.
  const cards = data?.billing_summary?.cards ?? [];

  const statuses = Object.entries(data?.status.by_status ?? {})
    .filter(([, count]) => count > 0)
    .sort(([, a], [, b]) => b - a);

  return (
    <div ref={chrome.container} className={chrome.containerClass}>
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
        actions={<PageActions chrome={chrome} onRefresh={refresh} refreshing={loading} />}
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

      <PagePeriodBar
        chrome={chrome}
        state={pageRange}
        // Stated on the bar itself, because it is true of this page and of no
        // other: four of the five blocks below are headcounts, and a headcount
        // has no period. Without the note the control looks broken.
        trailing={
          <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            growth only · headcounts are as of now
          </span>
        }
      />

      {/* ── Billing status summary header ─────────────────────────────── */}
      {/* One row, built from the statuses SYNC actually holds. There used to be
          a second row of fixed Active/VIP/Restricted/Disconnected cards beneath
          this one; it duplicated the first two and showed confident zeros for
          two statuses SYNC does not use at all, which is worse than absent. */}
      <Restricted
        require={WIDGET.subscriberBilling}
        fallback={<RestrictedPanel title="Billing Status Summary" height={120} />}
      >
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {first && cards.length === 0
            ? [0, 1, 2, 3].map((slot) => (
                <StatCard key={slot} label="—" value="—" tone="neutral" loading />
              ))
            : cards.map((card, index) => {
                const tone = statusTone(card.label);

                return (
                  <StatCard
                    key={card.key}
                    label={card.label}
                    value={formatNumber(card.count)}
                    tone={tone}
                    icon={statusIcon(card.label)}
                    loading={first}
                    caption={
                      // Only the leading card carries the base, so the row has
                      // one denominator rather than four repetitions of it.
                      index === 0 && billing ? (
                        <>
                          of <span className="font-semibold">{formatNumber(billing.total)}</span>{' '}
                          subscribers
                        </>
                      ) : (
                        STATUS_CAPTIONS[card.label.toLowerCase()] ?? 'billing status'
                      )
                    }
                  />
                );
              })}
        </div>
      </Restricted>

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

        {/* Three cards, not four. "Expected MRC" stood in the last slot and has
            been removed: it is what the active base *should* bill at plan price,
            which is a projection against a target rather than a measurement, and
            on a page that otherwise counts people it was the one figure nobody
            could reconcile against anything else on screen. The same number, on
            the same basis, is still on the Financial module where the rest of
            the money lives. */}
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
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
          {/* "New in range" named the mechanism — that a range control exists
              and this figure follows it — rather than the thing counted. The
              range is already stated in the caption underneath and on the
              control beside the heading, so the label says what these are. */}
          <StatCard
            label="New Subscribers"
            value={formatNumber(data?.growth.new_in_range)}
            tone="info"
            icon={<UserPlus size={18} />}
            loading={first}
            caption={data?.range_label}
          />
        </div>
      </Card>

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

            {(data?.status.excluded ?? 0) > 0 && (
              <p className={`mt-2 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                {pluralise(data?.status.excluded ?? 0, 'pending application')} excluded —
                an application that has not been activated is not a subscriber.
              </p>
            )}
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

      {/* ── Geography: every barangay, not a top ten ───────────────────── */}
      <Restricted
        require={WIDGET.subscriberBarangay}
        fallback={<RestrictedPanel title="Barangay Breakdown" height={240} />}
      >
        <BarangayTable rows={data?.barangays ?? []} loading={first} error={error} />
      </Restricted>

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Counts are as of {data?.as_of ?? 'today'}. Pending applications are excluded throughout —
        they are not subscribers. <strong>Restricted</strong> is what the operating systems record as
        suspended, and <strong>Disconnected</strong> what they record as expired or overdue. Every
        card and slice above is built from the statuses the source system itself holds, so one added
        in SYNC appears here without a change to this portal.
      </p>
    </ReportingPage>
    </div>
  );
};

export default SubscriberAnalytics;
