import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  Banknote,
  Building2,
  CalendarClock,
  ClipboardList,
  CreditCard,
  Landmark,
  Lock,
  ShieldAlert,
  TrendingUp,
  UserCheck,
  Users,
  Wallet,
  Wifi,
  Wrench,
  Trophy,
  Crown,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import WidgetRange from '../components/reporting/WidgetRange';
import BarangayNetworkTable from '../components/reporting/BarangayNetworkTable';
import FloatingRangeBar from '../components/reporting/FloatingRangeBar';
import PlanDistributionPanel from '../components/reporting/PlanDistributionPanel';
import { useWorkStream } from '../components/reporting/WorkStreamCard';
import WorkPipelinePanel from '../components/reporting/WorkPipelinePanel';
// The same two panels the Operations page renders, from one definition — see
// OperationsPanels.
import { QueuePanel, TurnaroundPanel } from '../components/reporting/OperationsPanels';
import { defaultFilters } from '../components/reporting/sectionShell';
import { ErrorBanner, PanelState, Pill, RankBadge, Table, Td, Th, Thead, Tr } from '../components/reporting/primitives';
import { RestrictedPanel } from '../components/rbac/Restricted';
import { usePermissions } from '../hooks/usePermissions';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { useLinkedRange } from '../hooks/useLinkedRange';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { reportingService } from '../services/reportingService';
import { ExecutiveOverviewData, OperationsData, VipAccountList } from '../types/reporting';
import { formatDate, formatMoney, formatNumber, formatPercent } from '../utils/format';

interface ExecutiveOverviewProps {
  refreshToken: number;
}

/**
 * The Executive Dashboard.
 *
 * Four sections, in the order a business owner reads them:
 *
 *   1. Finance     — income by channel, expenses including the SYNC fee, net
 *   2. Subscribers — billing status, network state, expiry runway, plans, geography
 *   3. Work        — applications, job orders and service orders, each on its own range
 *   4. Operations  — concerns, repair categories, technician leaderboard
 *
 * Every figure is the same figure the module it came from shows, arrived at by
 * the same code path — see ExecutiveOverviewService for why this view composes
 * section payloads rather than issuing SQL of its own.
 *
 * Default view is daily. A business owner opening this screen is asking what
 * happened today; the weekly default it replaced meant the first figure anyone
 * saw was a seven-day total that nobody had asked for.
 *
 * The three work cards do not follow the range at all. They report today, this
 * week and this month side by side (see WorkQueueCard), because a figure whose
 * period depends on a control most readers never touch cannot be quoted without
 * also quoting the control. Only the pipeline chart below them is range-driven,
 * and it keeps the docked control and the detach/re-link behaviour.
 *
 * The work data loads from its own endpoint, so moving the pipeline range does
 * not re-run the financial fan-out behind the rest of the page.
 */
const ExecutiveOverview: React.FC<ExecutiveOverviewProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { user } = usePermissions();

  // Daily: the question this page is opened to answer is "how are we doing
  // today", and every card that needs a wider window now states its own.
  const range = useWidgetRange('daily');

  const [data, setData] = useState<ExecutiveOverviewData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  // The pipeline chart follows the page range until its own control is touched,
  // at which point it detaches and says so. See useLinkedRange.
  const pipelineRange = useLinkedRange(range);

  // One request, not four. The cadence the three cards render is anchored on
  // today rather than on the range, so it is identical in every response — and
  // asking for it three more times would only be three more ways for the same
  // figure to arrive at different moments and flicker.
  const work = useWorkStream(pipelineRange, refreshToken);

  // Turnaround carries its own range, as it does on the Operations page: it is a
  // measure of work that *closed* inside a window, so it is the one panel here
  // that is meaningless without a period of its own. Monthly by default —
  // a day's closures are too few an average to read anything into.
  const slaRange = useWidgetRange('monthly');

  // Every database, which is what an executive view means by "the business".
  // Memoised because useReportingSection depends on this object by identity, and
  // a fresh one per render would refetch in a loop.
  const opsFilters = useMemo(() => defaultFilters(), []);

  // The queue panels below carry NO range: a backlog is what is open now, and
  // one filtered to a date window is not a backlog. Requested without a widget
  // range so this and the Operations page share a single cached response.
  const queuesSection = useReportingSection<OperationsData>(
    reportingService.getOperations,
    opsFilters,
    refreshToken
  );

  const slaSection = useReportingSection<OperationsData>(
    reportingService.getOperations,
    opsFilters,
    refreshToken,
    { dateFrom: slaRange.range.from, dateTo: slaRange.range.to }
  );

  const queues = queuesSection.data?.queues ?? [];
  const detachedCount = pipelineRange.linked ? 0 : 1;

  const relinkAll = useCallback(() => pipelineRange.relink(), [pipelineRange]);

  const load = useCallback(() => {
    let cancelled = false;

    setLoading(true);

    reportingService
      .getExecutiveOverview({ dateFrom: range.range.from, dateTo: range.range.to })
      .then((result) => {
        if (cancelled) return;

        setData(result);
        setError(null);
        setForbidden(false);
      })
      .catch((err) => {
        if (cancelled) return;

        if (err?.response?.status === 403) {
          setForbidden(true);
          setError(null);
          return;
        }

        setError(err?.response?.data?.message ?? 'Unable to build the executive summary right now.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [range.range.from, range.range.to]);

  useEffect(() => load(), [load, refreshToken]);

  const subs = data?.subscriber_overview;
  const finance = data?.financial_summary;
  const ops = data?.operations_tech;
  const first = loading && !data;

  if (forbidden) {
    return (
      <ReportingPage>
        <PageHeader title="Executive Dashboard" />
        <Card>
          <div className="flex items-start gap-3 py-6 px-2">
            <Lock size={20} className={isDarkMode ? 'text-gray-600' : 'text-gray-400'} />
            <div>
              <p className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                Restricted to executive roles
              </p>
              <p className={`text-sm mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                This view requires an executive role.
                {user?.role ? <> Your role ({user.role}) does not have access.</> : null}
              </p>
            </div>
          </div>
        </Card>
      </ReportingPage>
    );
  }

  const syncPrice = finance?.sync_price;
  const hostingFee = finance?.hosting_fee;

  return (
    <ReportingPage docked>
      {/* Docked out of the flow: a right-hand rail on desktop, a sticky bottom
          bar on a phone. The header keeps the full control — with the custom
          picker the bar deliberately omits — so nothing is only reachable
          through a gesture. */}
      <FloatingRangeBar
        state={range}
        overriddenCount={detachedCount}
        onRelinkAll={relinkAll}
      />

      <PageHeader
        title="Executive Dashboard"
        subtitle={
          <>
            Business overview
            {data && <> · {data.range_label}</>}
          </>
        }
        actions={<WidgetRange state={range} size="md" />}
      />

      {error && <ErrorBanner message={error} />}

      {/* Database availability warning */}
      {data && data.databases.failed.length > 0 && (
        <div
          className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
            isDarkMode
              ? 'border-amber-800/60 bg-amber-500/10 text-amber-200'
              : 'border-amber-200 bg-amber-50 text-amber-800'
          }`}
        >
          <AlertTriangle size={15} className="mt-0.5 flex-shrink-0" />
          <div className="min-w-0">
            <p>
              <strong>
                {data.databases.answered.length} of {data.databases.total} databases
              </strong>{' '}
              answered. Some data may be incomplete.
            </p>
          </div>
        </div>
      )}

      {/* ── HEADLINE ──────────────────────────────────────────────────── */}
      {finance?.available && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <KpiCard
            label="Total Income"
            value={first ? '—' : formatMoney(finance.total_income)}
            icon={<Banknote size={18} />}
            tone="success"
            caption="Cash + PNB + Payment Portal"
          />
          <KpiCard
            label="Projected Monthly"
            value={first ? '—' : formatMoney(finance.projected_monthly)}
            icon={<TrendingUp size={18} />}
            tone="info"
            caption={
              finance.days_elapsed
                ? `month-to-date ÷ ${finance.days_elapsed} ${
                    finance.days_elapsed === 1 ? 'day' : 'days'
                  } × ${finance.days_in_month ?? 30}`
                : 'month-to-date rate × days in month'
            }
          />
          <KpiCard
            label="Total Expenses"
            value={first ? '—' : formatMoney(finance.total_expenses)}
            icon={<Building2 size={18} />}
            tone="warning"
            caption={`OPEX ${formatMoney(finance.opex)} · CAPEX ${formatMoney(finance.capex)}`}
          />
          <KpiCard
            label="Net Income"
            value={first ? '—' : formatMoney(finance.net)}
            icon={<Wallet size={18} />}
            tone={(finance.net ?? 0) >= 0 ? 'success' : 'danger'}
            caption={
              finance.margin_pct !== null && finance.margin_pct !== undefined
                ? `${formatPercent(finance.margin_pct)} margin`
                : 'income − expenses'
            }
          />
        </div>
      )}

      {/* ── 1. FINANCE ───────────────────────────────────────────────── */}
      {finance?.masked ? (
        <RestrictedPanel title="Finance" height={220} />
      ) : finance?.available ? (
        <Card flush>
          <CardHeader title="Finance" subtitle={finance.range_label} icon={<Wallet size={16} />} />
          <CardBody>
            {/* Income */}
            <SectionLabel label="Income" icon={<Banknote size={14} />} />
            {/* Daily and weekly side by side, each stating its own arithmetic.
                The tile labelled "Weekly Sales Average" used to render the daily
                rate — the last seven days divided by seven — so the figure was a
                seventh of what its label promised. Both are now shown, and the
                weekly one is the daily one multiplied back out, which is exactly
                the last seven days' takings. */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-2">
              <MiniKpi
                label="Total Income"
                value={first ? '—' : formatMoney(finance.total_income)}
                tone="success"
              />
              <MiniKpi
                label="Daily Sales Average"
                value={first ? '—' : formatMoney(finance.weekly_average)}
                tone="info"
                caption="last 7 days ÷ 7"
              />
              <MiniKpi
                label="Weekly Sales Average"
                value={first ? '—' : formatMoney(finance.week_income)}
                tone="info"
                caption={`daily average × ${finance.week_days ?? 7}`}
              />
              <MiniKpi
                label="Month to Date"
                value={first ? '—' : formatMoney(finance.month_income)}
                caption={
                  finance.days_elapsed
                    ? `${finance.days_elapsed} of ${finance.days_in_month} days`
                    : undefined
                }
              />
              <MiniKpi
                label="Projected Monthly"
                value={first ? '—' : formatMoney(finance.projected_monthly)}
                tone="info"
              />
            </div>

            {/* An unmatched residue means a payment method matched none of the
                configured channels. Silent until it happens, because that is the
                only time it is worth a line on an executive screen. */}
            {(finance.unmatched_income ?? 0) > 0 && (
              <p className={`text-[11px] mb-4 ${isDarkMode ? 'text-amber-300/80' : 'text-amber-700'}`}>
                {formatMoney(finance.unmatched_income)} collected through a payment method that
                matches no configured channel, and is therefore outside Total Income.
              </p>
            )}

            {/* Income by Channel */}
            {finance.channels && Object.keys(finance.channels).length > 0 && (
              <>
                <SectionLabel label="Income by Channel" icon={<CreditCard size={14} />} />
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                  {Object.entries(finance.channels).map(([key, channel]) => (
                    <div
                      key={key}
                      className={`rounded-lg px-3 py-2.5 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
                    >
                      <p
                        className={`flex items-center gap-1.5 text-xs ${
                          isDarkMode ? 'text-gray-400' : 'text-gray-500'
                        }`}
                      >
                        {key === 'cash' ? (
                          <Banknote size={14} />
                        ) : key === 'pnb' ? (
                          <Landmark size={14} />
                        ) : (
                          <CreditCard size={14} />
                        )}
                        {channel.label}
                      </p>
                      <p className="text-lg font-bold tabular-nums truncate">
                        {formatMoney(channel.total)}
                      </p>
                      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        {formatPercent(channel.share_pct)} of collections
                      </p>
                    </div>
                  ))}
                </div>
              </>
            )}

            {/* Expenses, including the SYNC platform fee */}
            <SectionLabel label="Expenses" icon={<Building2 size={14} />} />
            <div className="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-3">
              <MiniKpi label="OPEX" value={first ? '—' : formatMoney(finance.opex)} tone="warning" />
              <MiniKpi label="CAPEX" value={first ? '—' : formatMoney(finance.capex)} />
              {/* Three states, not two. An unset rate and a headcount no
                  database could produce are different problems with different
                  fixes, and both must be distinguishable from a genuine ₱0.00 —
                  which under a Net Income figure would read as "SYNC is free".

                  Labelled "/mo" in the value itself rather than only in the
                  caption: this is the one pair of figures on the page whose
                  period is fixed while the range control moves everything around
                  them, and a peso amount with no period beside range-scoped
                  peso amounts reads as though it followed the range too. */}
              <MiniKpi
                label="SYNC Price"
                value={
                  first
                    ? '—'
                    : !syncPrice?.configured
                    ? 'Not set'
                    : syncPrice.total === null
                    ? '—'
                    : `${formatMoney(syncPrice.total)}/mo`
                }
                tone={syncPrice?.configured && syncPrice.total !== null ? 'warning' : 'neutral'}
                caption={
                  !syncPrice?.configured
                    ? 'set a rate in Settings'
                    : syncPrice.total === null
                    ? 'subscriber count unavailable'
                    : `${formatMoney(syncPrice.rate)} × ${formatNumber(syncPrice.billable_accounts)} per month`
                }
              />
              <MiniKpi
                label="Hosting Fee"
                value={
                  first
                    ? '—'
                    : !hostingFee?.configured
                    ? 'Not set'
                    : hostingFee.total === null
                    ? '—'
                    : `${formatMoney(hostingFee.total)}/mo`
                }
                tone={hostingFee?.configured && hostingFee.total !== null ? 'warning' : 'neutral'}
                caption={
                  !hostingFee?.configured
                    ? 'set a fee in Settings'
                    : 'flat monthly charge'
                }
              />
              <MiniKpi
                label="Total Expenses"
                value={first ? '—' : formatMoney(finance.total_expenses)}
                tone="danger"
                caption="OPEX + CAPEX"
              />
            </div>

            <p className={`text-[11px] mb-5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
              SYNC is licensed per subscriber, so its cost is a headcount times a rate rather than a
              ledger entry — it appears in no expenses module, and is therefore reported beside Total
              Expenses rather than inside it, which keeps that figure reconcilable against the
              Financial module. Both it and the hosting fee are monthly charges and are shown as
              monthly amounts whatever range is selected above; neither is netted off Net Income,
              because a month&rsquo;s cost cannot be taken off a day&rsquo;s — or a year&rsquo;s —
              income and still mean anything.{' '}
              {syncPrice?.excluded_statuses?.length
                ? `Accounts on ${syncPrice.excluded_statuses
                    .map((status) => status.replace(/\b\w/g, (letter) => letter.toUpperCase()))
                    .join(' and ')} status are excluded from the headcount.`
                : null}
            </p>

            {/* Result */}
            <SectionLabel label="Result" icon={<Wallet size={14} />} />
            {/* Three figures, not four. "Net after Platform Costs" used to sit
                here, taking a month of SYNC and hosting off a net that follows
                the range control — so on a daily range it subtracted a month's
                cost from a day's income, and on a yearly range it subtracted one
                month from twelve. There is no range-independent way to net a
                fixed monthly charge against a range-scoped income, so the figure
                is not produced at all; the two charges are reported under
                Expenses as monthly amounts instead. */}
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <MiniKpi label="Gross Revenue" value={first ? '—' : formatMoney(finance.gross)} tone="success" />
              <MiniKpi
                label="Net Income"
                value={first ? '—' : formatMoney(finance.net)}
                tone={(finance.net ?? 0) >= 0 ? 'success' : 'danger'}
                caption="income − OPEX − CAPEX"
              />
              <MiniKpi
                label="Margin"
                value={
                  first
                    ? '—'
                    : finance.margin_pct !== null && finance.margin_pct !== undefined
                    ? formatPercent(finance.margin_pct)
                    : '—'
                }
              />
            </div>
          </CardBody>
        </Card>
      ) : null}

      {/* ── 2. SUBSCRIBERS ───────────────────────────────────────────── */}
      {subs?.available && (
        <Card flush>
          <CardHeader
            title="Subscribers"
            subtitle="Billing status, network state and expiry runway"
            icon={<Users size={16} />}
          />
          <CardBody>
            <SectionLabel label="Billing Status" icon={<UserCheck size={14} />} />
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
              <MiniKpi label="Active" value={first ? '—' : formatNumber(subs.billing?.active)} tone="success" />
              <MiniKpi label="Inactive" value={first ? '—' : formatNumber(subs.billing?.inactive)} tone="warning" />
              <MiniKpi label="VIP" value={first ? '—' : formatNumber(subs.billing?.vip)} tone="info" />
              <MiniKpi label="Pullout" value={first ? '—' : formatNumber(subs.billing?.pullout)} tone="danger" />
            </div>

            {/* Network status. Four mutually exclusive counts over one row set,
                so they sum to the base — see the driver's networkStatus. */}
            <SectionLabel label="Network Status" icon={<Wifi size={14} />} />
            {subs.network === null ? (
              <EmptyState label="No monitored system reports live network state." />
            ) : (
              <>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-1">
                  <MiniKpi label="Online" value={first ? '—' : formatNumber(subs.network?.online)} tone="success" />
                  <MiniKpi label="Offline" value={first ? '—' : formatNumber(subs.network?.offline)} />
                  <MiniKpi
                    label="Restricted"
                    value={first ? '—' : formatNumber(subs.network?.restricted)}
                    tone="warning"
                  />
                  <MiniKpi
                    label="Disconnected"
                    value={first ? '—' : formatNumber(subs.network?.disconnected)}
                    tone="danger"
                  />
                </div>
                <p className={`text-[11px] ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                  Counted from online_status session state and filtered to subscribers only, so
                  the four add up to {formatNumber(subs.network?.total)} subscribers.
                </p>

                {/* Provisioning faults hiding inside Offline. Silent until there
                    are any, because on a healthy fleet this is zero and a line
                    saying so is noise. */}
                {((subs.network?.not_found ?? 0) > 0 ||
                  (subs.network?.no_session_row ?? 0) > 0) && (
                  <p className={`text-[11px] mt-1 ${isDarkMode ? 'text-amber-300/80' : 'text-amber-700'}`}>
                    Within Offline:{' '}
                    {(subs.network?.not_found ?? 0) > 0 && (
                      <>
                        {formatNumber(subs.network?.not_found)} with no RADIUS user
                        {(subs.network?.no_session_row ?? 0) > 0 ? ', ' : ''}
                      </>
                    )}
                    {(subs.network?.no_session_row ?? 0) > 0 && (
                      <>{formatNumber(subs.network?.no_session_row)} never synced</>
                    )}
                    . These are provisioning gaps rather than subscribers who are switched off.
                  </p>
                )}

                <div className="mb-5" />
              </>
            )}

            {/* Expiry runway */}
            <SectionLabel label="Expiring Soon" icon={<CalendarClock size={14} />} />
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <MiniKpi
                label="Expiring in 3 Days"
                value={
                  first
                    ? '—'
                    : subs.expiring?.in_3_days === null
                    ? 'Not tracked'
                    : formatNumber(subs.expiring?.in_3_days)
                }
                tone="danger"
                caption="prepaid, active only"
              />
              <MiniKpi
                label="Expiring in 7 Days"
                value={
                  first
                    ? '—'
                    : subs.expiring?.in_7_days === null
                    ? 'Not tracked'
                    : formatNumber(subs.expiring?.in_7_days)
                }
                tone="warning"
                caption="includes the 3-day count"
              />
            </div>

            {/* Who the VIP counter above is counting. The count alone says how
                much is being given away; only the list says to whom, and that is
                the version anyone can act on. */}
            <div className="mt-5">
              <VipAccountsTable
                list={subs.vip_accounts}
                vipCount={subs.billing?.vip}
                loading={first}
              />
            </div>
          </CardBody>
        </Card>
      )}

      {/* Plan distribution: pie left, sortable table right. */}
      {subs?.available && (
        <PlanDistributionPanel
          rows={subs.plan_distribution ?? []}
          loading={first}
          error={null}
        />
      )}

      {/* ── 3. WORK: APPLY · INSTALL · REPAIR ────────────────────────── */}
      {/* The Operations queues, exactly as the Operations page renders them —
          the status pipeline, the open backlog and how long the oldest item has
          been waiting.

          These replaced three today/week/month cards that reported the same work
          without either of the two facts anyone acts on: how much is open, and
          how old the oldest is. A count of what happened this week does not say
          whether anything is stuck.

          `plainLabels` keeps what those cards were good at. They titled the
          queues in the words the business uses rather than the table names, and
          named each status for the work rather than for its database value —
          "Installed" and "To be installed", not "Done" and "In Progress". That
          translation is preserved here and belongs to this page only; the
          Operations page keeps its source's own vocabulary, which is what a
          field manager administers the system in. */}
      {queuesSection.error ? (
        <ErrorBanner message={queuesSection.error} />
      ) : queues.length === 0 ? (
        <Card>
          <PanelState
            loading={queuesSection.loading && !queuesSection.data}
            empty={!(queuesSection.loading && !queuesSection.data)}
            emptyMessage="No monitored system records field-work queues."
            height={180}
          >
            <span />
          </PanelState>
        </Card>
      ) : (
        <div className={`grid grid-cols-1 gap-4 ${queues.length > 1 ? 'lg:grid-cols-3' : ''}`}>
          {queues.map((queue) => (
            <QueuePanel key={queue.key} queue={queue} plainLabels />
          ))}
        </div>
      )}

      {/* ── Turnaround (SLA) ─────────────────────────────────────────── */}
      {/* Replaced the Resolution Speed and Free Connection pair. Resolution
          Speed counted what was finished; this reports how long it took and
          which work type is the slow one, which is the question a completion
          count only raises. Free Connections moved into the Subscribers section
          above as a named list — a count of accounts being given away is worth
          much less than knowing which ones. */}
      <TurnaroundPanel
        turnaround={slaSection.data?.turnaround}
        byType={slaSection.data?.turnaround_by_type ?? []}
        loading={slaSection.loading && !slaSection.data}
        rangeLabel={slaSection.data?.range_label}
        actions={<WidgetRange state={slaRange} />}
      />

      {/* Apply → Install → Repair: chart left, the same numbers right. */}
      <WorkPipelinePanel
        points={work.data?.timeline ?? []}
        rangeLabel={work.data?.range_label}
        range={pipelineRange}
        loading={work.loading}
        error={work.error}
      />

      {/* Barangay breakdown: pie left, table right. */}
      {subs?.available && (
        <BarangayNetworkTable rows={subs.barangays ?? []} loading={first} error={null} />
      )}

      {/* ── 4. OPERATIONS ────────────────────────────────────────────── */}
      {ops?.available && (
        <Card flush>
          <CardHeader title="Operations" subtitle="Field work summary" icon={<Wrench size={16} />} />
          <CardBody>
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div>
                <SectionLabel label="Reported Concerns" icon={<ShieldAlert size={14} />} />
                {(ops.concerns?.length ?? 0) === 0 ? (
                  <EmptyState label="No concerns reported" />
                ) : (
                  <div className="space-y-2">
                    {ops.concerns?.map((concern) => (
                      <div
                        key={concern.label}
                        className={`flex items-center justify-between rounded-lg px-3 py-2 ${
                          isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'
                        }`}
                      >
                        <span className={`text-sm ${isDarkMode ? 'text-gray-200' : 'text-gray-700'}`}>
                          {concern.label}
                        </span>
                        <Pill tone="warning">{formatNumber(concern.count)}</Pill>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div>
                <SectionLabel label="Repair Categories" icon={<Wrench size={14} />} />
                {(ops.repair_categories?.length ?? 0) === 0 ? (
                  <EmptyState label="No repair records" />
                ) : (
                  <div className="space-y-2">
                    {ops.repair_categories?.map((cat) => (
                      <div
                        key={cat.label}
                        className={`flex items-center justify-between rounded-lg px-3 py-2 ${
                          isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'
                        }`}
                      >
                        <span className={`text-sm ${isDarkMode ? 'text-gray-200' : 'text-gray-700'}`}>
                          {cat.label}
                        </span>
                        <Pill tone="info">{formatNumber(cat.count)}</Pill>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>

            {(ops.top_tech?.length ?? 0) > 0 && (
              <div className="mt-6">
                <SectionLabel label="Top Technicians" icon={<Trophy size={14} />} />
                <Table>
                  <Thead>
                    <Th width="40px">#</Th>
                    <Th>Technician</Th>
                    <Th align="right">JO Done</Th>
                    <Th align="right">SO Done</Th>
                    <Th align="right">Total</Th>
                    <Th align="right">Avg Time</Th>
                  </Thead>
                  <tbody>
                    {ops.top_tech?.map((tech, idx) => (
                      <Tr key={tech.id ?? tech.name}>
                        <Td>
                          <RankBadge rank={idx + 1} />
                        </Td>
                        <Td className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                          {tech.name}
                        </Td>
                        <Td align="right" className="tabular-nums">
                          {formatNumber(tech.job_orders_done)}
                        </Td>
                        <Td align="right" className="tabular-nums">
                          {formatNumber(tech.service_orders_done)}
                        </Td>
                        <Td align="right" className="font-semibold tabular-nums">
                          {formatNumber(tech.completed)}
                        </Td>
                        <Td
                          align="right"
                          className={`tabular-nums ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}
                        >
                          {tech.average_minutes !== null && tech.average_minutes !== undefined
                            ? `${tech.average_minutes} min`
                            : '—'}
                        </Td>
                      </Tr>
                    ))}
                  </tbody>
                </Table>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      <p className={`text-xs ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
        Figures sourced from section modules. Counts as of {data?.as_of ?? 'today'}.
      </p>
    </ReportingPage>
  );
};

// ── Sub-components ────────────────────────────────────────────────────

type Tone = 'success' | 'danger' | 'warning' | 'neutral' | 'info';

const TONE_TEXT: Record<Tone, string> = {
  success: 'text-emerald-600 dark:text-emerald-400',
  danger: 'text-red-500 dark:text-red-400',
  warning: 'text-amber-500 dark:text-amber-400',
  neutral: '',
  info: 'text-blue-600 dark:text-blue-400',
};

/** Hero KPI card — large number with icon badge and caption. */
const KpiCard: React.FC<{
  label: string;
  value: React.ReactNode;
  icon?: React.ReactNode;
  caption?: React.ReactNode;
  tone?: Tone;
}> = ({ label, value, icon, caption, tone = 'neutral' }) => {
  const isDarkMode = useTheme();

  const toneBg: Record<Tone, string> = {
    success: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
    danger: 'bg-red-100 text-red-500 dark:bg-red-500/15 dark:text-red-400',
    warning: 'bg-amber-100 text-amber-500 dark:bg-amber-500/15 dark:text-amber-400',
    neutral: 'bg-gray-100 text-gray-500 dark:bg-gray-700/60 dark:text-gray-300',
    info: 'bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
  };

  return (
    <div
      className={`rounded-xl border p-4 sm:p-5 ${
        isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200 shadow-sm'
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className={`text-sm mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{label}</p>
          <p
            className={`text-2xl sm:text-3xl font-bold tracking-tight truncate ${
              TONE_TEXT[tone] || (isDarkMode ? 'text-white' : 'text-gray-900')
            }`}
          >
            {value}
          </p>
        </div>
        {icon && (
          <span
            className={`flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center ${toneBg[tone]}`}
          >
            {icon}
          </span>
        )}
      </div>
      {caption && (
        <p className={`mt-2 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{caption}</p>
      )}
    </div>
  );
};

/** Small count tile inside a section. */
const MiniKpi: React.FC<{
  label: string;
  value: React.ReactNode;
  tone?: Tone;
  caption?: React.ReactNode;
}> = ({ label, value, tone = 'neutral', caption }) => {
  const isDarkMode = useTheme();

  return (
    <div className={`rounded-lg px-3 py-2.5 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}>
      <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{label}</p>
      <p
        className={`text-xl font-bold tabular-nums truncate mt-0.5 ${
          TONE_TEXT[tone] || (isDarkMode ? 'text-white' : 'text-gray-900')
        }`}
      >
        {value}
      </p>
      {caption && (
        <p className={`text-[11px] mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
          {caption}
        </p>
      )}
    </div>
  );
};

/** Section label within a card. */
const SectionLabel: React.FC<{ label: string; icon?: React.ReactNode }> = ({ label, icon }) => {
  const isDarkMode = useTheme();

  return (
    <p
      className={`flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider mb-2 mt-1 ${
        isDarkMode ? 'text-gray-400' : 'text-gray-500'
      }`}
    >
      {icon}
      {label}
    </p>
  );
};

/** Empty state for a list. */
const EmptyState: React.FC<{ label: string }> = ({ label }) => {
  const isDarkMode = useTheme();

  return (
    <p className={`text-sm py-4 text-center ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
      {label}
    </p>
  );
};

/**
 * Every account exempted from billing by a VIP billing status, by name.
 *
 * The VIP counter three rows above says how many; this says which. A count is
 * something to worry about, a list is something to review — and reviewing them
 * previously meant leaving this dashboard and opening the operating system.
 *
 * Ordered by expiry, soonest first, with open-ended VIPs last. That is the order
 * the list gets used in: an exemption lapsing this week needs a decision, one
 * with no end date needs a conversation, and one granted years ago needs
 * neither today.
 *
 * Three states, not two:
 *
 *  - `null` list — no monitored system models VIP at all. Reported as "not
 *    tracked", because an empty table would claim there are none.
 *  - empty list on a source that does model it — genuinely nobody, said plainly.
 *  - a list, possibly capped. `total` always states the true count, so a capped
 *    list is visibly capped rather than quietly short.
 *
 * The count beside the heading is the list's own total and is cross-checked
 * against the VIP counter above: a source that reports VIP accounts but no rows
 * for them is a gap in this panel, not an absence of VIP accounts, and saying so
 * is better than showing an empty table under a non-zero counter.
 */
const VipAccountsTable: React.FC<{
  list?: VipAccountList | null;
  vipCount?: number;
  loading: boolean;
}> = ({ list, vipCount, loading }) => {
  const isDarkMode = useTheme();

  const rows = list?.rows ?? [];
  const total = list?.total ?? 0;
  const showSource = rows.some((row) => Boolean(row.source_label));

  // A VIP counter above with no rows here means the list could not be built,
  // which is a different thing from there being no VIP accounts.
  const countedButUnlisted = !loading && list !== null && list !== undefined
    && total === 0 && (vipCount ?? 0) > 0;

  return (
    <>
      <SectionLabel label="VIP Accounts" icon={<Crown size={14} />} />

      {loading ? (
        <EmptyState label="Loading VIP accounts…" />
      ) : list === null || list === undefined ? (
        <EmptyState label="No monitored system records a VIP billing status." />
      ) : countedButUnlisted ? (
        <EmptyState
          label={`${formatNumber(vipCount)} accounts carry VIP status, but the list could not be built from this source.`}
        />
      ) : rows.length === 0 ? (
        <EmptyState label="No accounts are on VIP billing status." />
      ) : (
        <>
          <div className="overflow-x-auto">
            <Table>
              <Thead>
                <Th>Account No.</Th>
                <Th>Subscriber</Th>
                <Th>Plan</Th>
                <Th>Barangay</Th>
                <Th>Contact</Th>
                <Th align="right">VIP Expires</Th>
                {showSource && <Th>Database</Th>}
              </Thead>
              <tbody>
                {rows.map((row) => (
                  <Tr key={`${row.source ?? ''}-${row.id}`}>
                    <Td className="font-medium tabular-nums">{row.account_number || '—'}</Td>
                    <Td className={isDarkMode ? 'text-gray-100' : 'text-gray-900'}>
                      {row.subscriber || '—'}
                    </Td>
                    <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                      {row.plan || '—'}
                    </Td>
                    <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                      {row.barangay || '—'}
                    </Td>
                    <Td className={`tabular-nums ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                      {row.contact_number || '—'}
                    </Td>
                    <Td align="right">
                      {/* An open-ended VIP is a real state, not missing data —
                          named as such rather than shown as a dash. */}
                      {row.vip_expiration ? (
                        <span className="tabular-nums">{formatDate(row.vip_expiration)}</span>
                      ) : (
                        <Pill tone="info">No end date</Pill>
                      )}
                    </Td>
                    {showSource && (
                      <Td className={isDarkMode ? 'text-gray-500' : 'text-gray-400'}>
                        {row.source_label}
                      </Td>
                    )}
                  </Tr>
                ))}
              </tbody>
            </Table>
          </div>

          <p className={`text-[11px] mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
            {list.truncated ? (
              <>
                Showing {formatNumber(rows.length)} of {formatNumber(total)} accounts on VIP billing
                status. The list is capped — open Subscriber Analytics for the full set.
              </>
            ) : (
              <>
                {formatNumber(total)} accounts on VIP billing status, connected and not billed.
                Accounts on a plan merely <em>named</em> for VIP are a separate population and are
                not listed here.
              </>
            )}
          </p>
        </>
      )}
    </>
  );
};

export default ExecutiveOverview;
