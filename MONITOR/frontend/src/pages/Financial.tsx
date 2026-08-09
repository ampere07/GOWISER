import React from 'react';
import {
  ArrowDownCircle,
  ArrowUpCircle,
  Banknote,
  Briefcase,
  Building2,
  CalendarDays,
  CreditCard,
  HardDrive,
  Landmark,
  Printer,
  TrendingDown,
  Wallet,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import WidgetRange from '../components/reporting/WidgetRange';
import { AccentCard } from '../components/reporting/StatCard';
import BreakdownTable from '../components/reporting/BreakdownTable';
import { DonutChart, TrendChart } from '../components/reporting/charts';
import {
  Bar,
  Button,
  Dot,
  ErrorBanner,
  PanelState,
  Table,
  TableState,
  Td,
  Th,
  Thead,
  Tr,
} from '../components/reporting/primitives';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice } from '../components/reporting/DatabaseFilter';
import { PageActions, PagePeriodBar, usePageChrome } from '../components/reporting/PageChrome';
import { Restricted, RestrictedPanel, MaskedValue } from '../components/rbac/Restricted';
import PrintReportOverlay from '../components/print/PrintReportOverlay';
import { MetricTooltip } from '../components/common/MetricTooltip';
import { useReportingSection } from '../hooks/useReportingSection';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { useLinkedRange } from '../hooks/useLinkedRange';
import { useMonitorStore } from '../store/monitorStore';
import { reportingService } from '../services/reportingService';
import { FinancialData, IncomeChannel } from '../types/reporting';
import { ACTION, WIDGET } from '../types/rbac';
import { UserData } from '../types/api';
import { formatDate, formatMoney, formatPercent, pluralise } from '../utils/format';

interface FinancialProps {
  refreshToken: number;
  user: UserData;
}

/** Icon and accent per collection channel, so a channel reads at a glance. */
const CHANNEL_STYLE: Record<string, { icon: React.ReactNode; color: string }> = {
  cash: { icon: <Banknote size={15} />, color: '#198754' },
  pnb: { icon: <Landmark size={15} />, color: '#0d6efd' },
  portal: { icon: <CreditCard size={15} />, color: '#7c3aed' },
  other: { icon: <Wallet size={15} />, color: '#6c757d' },
};

/**
 * Financial — money in, money out, and everything behind both.
 *
 * Every widget owns its date range. That replaced a single page-level period,
 * which forced the whole screen onto one window and made the comparison this
 * page exists for — this month's collections against the twelve-month trend —
 * impossible to draw.
 *
 * Widget permissions are enforced twice over. The backend strips or nulls each
 * block before the payload leaves (App\Support\PayloadMasker), and `Restricted`
 * here keeps the corresponding panel from rendering as an empty card — which
 * would read as a fault rather than as a restriction.
 */
const Financial: React.FC<FinancialProps> = ({ refreshToken, user }) => {
  const isDarkMode = useTheme();
  const { can } = usePermissions();
  const activeSource = useMonitorStore((state) => state.activeSource);
  const { filters, update, reset, branches, databases } = useSectionFilters('financial');

  const [printOpen, setPrintOpen] = React.useState(false);
  // Bumped by the page's own Refresh button, on top of the dashboard poll.
  const [reloads, setReloads] = React.useState(0);

  const chrome = usePageChrome();

  /**
   * The page period, and the widgets that follow it.
   *
   * Daily rather than month-to-date: this page is opened to answer "what came in
   * today" far more often than "how is the month going", and the month is now
   * one click away at the top rather than six clicks across the card headers.
   *
   * Every widget is linked, so the bar moves all of them together — and each
   * keeps its own control for the comparison this page exists to make, holding
   * revenue on the year while the trend sits on the month. A widget someone
   * moves detaches and says so; see useLinkedRange.
   *
   * The trend is the one exception, seeded on the month whatever the page is on:
   * a trend over a single day is one point, which is not a shape.
   */
  const pageRange = useWidgetRange('daily');

  const headlineRange = useLinkedRange(pageRange);
  const channelsRange = useLinkedRange(pageRange);
  const opexRange = useLinkedRange(pageRange);
  const breakdownRange = useLinkedRange(pageRange);
  const trendRange = useWidgetRange('monthly');

  /** Month-to-date, for the printable ledger. See the overlay for why. */
  const printRange = useWidgetRange('monthly');

  const headline = useReportingSection<FinancialData>(
    reportingService.getFinancial,
    filters,
    refreshToken + reloads,
    { dateFrom: headlineRange.range.from, dateTo: headlineRange.range.to }
  );

  const trend = useReportingSection<FinancialData>(reportingService.getFinancial, filters, refreshToken + reloads, {
    dateFrom: trendRange.range.from,
    dateTo: trendRange.range.to,
    period: trendRange.granularity,
  });

  const channels = useReportingSection<FinancialData>(
    reportingService.getFinancial,
    filters,
    refreshToken + reloads,
    { dateFrom: channelsRange.range.from, dateTo: channelsRange.range.to }
  );



  const opex = useReportingSection<FinancialData>(reportingService.getFinancial, filters, refreshToken + reloads, {
    dateFrom: opexRange.range.from,
    dateTo: opexRange.range.to,
  });

  const breakdowns = useReportingSection<FinancialData>(
    reportingService.getFinancial,
    filters,
    refreshToken + reloads,
    { dateFrom: breakdownRange.range.from, dateTo: breakdownRange.range.to }
  );

  const { data, loading, error, source, sourceLabel, substituted } = headline;

  const kpi = data?.kpi;
  // Calendar-anchored, so it is read off the headline payload rather than
  // fetched again: the figures are identical whatever range that widget is on.
  const rolling = data?.rolling;
  const first = loading && !data;
  const surplus = (kpi?.net ?? 0) >= 0;
  const seesRevenue = can(WIDGET.financialRevenue);

  // Bars are scaled within their own measure, so income bars compare income
  // across horizons and expense bars compare expenses. One shared maximum would
  // flatten expenses into invisibility whenever income is larger, which is most
  // of the time.
  const periods = data?.periods ?? [];
  const maxIncome = periods.reduce((max, period) => Math.max(max, period.income), 0);
  const maxExpenses = periods.reduce((max, period) => Math.max(max, period.expenses), 0);

  const refresh = () => {
    // Bypasses the client cache, as the header's own refresh does — otherwise
    // the button appears to do nothing for up to ten seconds.
    reportingService.invalidate(source || undefined);
    setReloads((count) => count + 1);
  };

  return (
    // `financial-module` scopes the monochrome print rules in index.css. A
    // financial printout goes into a folder and gets signed, so it prints as
    // black text on white paper regardless of the theme on screen.
    <div className={`financial-module ${chrome.containerClass}`} ref={chrome.container}>
      <ReportingPage>
        <PageHeader
          title="Financial"
          subtitle={
            <>
              Income · Expenses · Net
              {sourceLabel && <> · {sourceLabel}</>}
              {data && data.branch_label !== 'All branches' && <> · {data.branch_label}</>}
            </>
          }
          actions={
            <PageActions
              chrome={chrome}
              onRefresh={refresh}
              refreshing={loading}
              extra={
                // Printing puts the ledger on paper and out of the building,
                // which is a stronger act than reading it — so it is a separate
                // grant, and the route enforces the same one.
                <Restricted require={ACTION.financialExport}>
                  <Button
                    variant="primary"
                    icon={<Printer size={14} />}
                    onClick={() => setPrintOpen(true)}
                    disabled={!data}
                  >
                    Print Report
                  </Button>
                </Restricted>
              }
            />
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

        <PagePeriodBar chrome={chrome} state={pageRange} />

        {/* ── Headline ──────────────────────────────────────────────────── */}
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
            <div className="min-w-0">
              <h3 className={`font-bold text-base ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                Headline
              </h3>
              <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                {data?.range_label ?? '—'} · as of {data?.as_of ?? '—'}
              </p>
            </div>
            <WidgetRange state={headlineRange} />
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <AccentCard
              label="Income"
              value={seesRevenue ? formatMoney(kpi?.income) : <MaskedValue />}
              tone="success"
              icon={<ArrowDownCircle size={15} />}
              caption={kpi ? pluralise(kpi.income_count, 'payment') : undefined}
              loading={first}
            >
              {/* Office Collection and Portal Collection were two MiniStats
                  here and have been removed. They restated, at a smaller size
                  and under different names, two of the three tiles in the Income
                  Channels panel immediately below — where the same split is
                  drawn with its share of income, its underlying payment methods
                  and a chart. A headline that repeats the panel under it makes
                  the page longer without answering anything new, and gave two
                  places to quote "office collections" from.

                  The by-charge-type breakdown that hung off them has gone with
                  them for the same reason: it is a breakdown of a figure this
                  card no longer shows. */}
            </AccentCard>

            <AccentCard
              label="Expenses"
              value={seesRevenue ? formatMoney(kpi?.expenses) : <MaskedValue />}
              tone="danger"
              icon={<ArrowUpCircle size={15} />}
              caption={kpi ? pluralise(kpi.expenses_count, 'record') : undefined}
              loading={first}
            />

            <AccentCard
              label="Net"
              value={seesRevenue ? formatMoney(kpi?.net) : <MaskedValue />}
              tone={surplus ? 'success' : 'danger'}
              icon={<Briefcase size={15} />}
              caption={
                kpi?.margin_pct !== null && kpi?.margin_pct !== undefined
                  ? `${formatPercent(kpi.margin_pct)} ${surplus ? 'margin' : 'loss ratio'}`
                  : 'no income this period'
              }
              loading={first}
            />
          </div>
        </Card>

        {/* ── Income channels: Cash · PNB · Payment Portal ──────────────── */}
        <Restricted
          require={WIDGET.financialChannels}
          fallback={<RestrictedPanel title="Income Channels" height={200} />}
        >
          <Card flush>
            <CardHeader
              title="Income Channels"
              subtitle="Cash over the counter, PNB deposits, and Payment Portal collections"
              icon={<Wallet size={16} />}
              actions={<WidgetRange state={channelsRange} />}
            />
            <CardBody>
              <PanelState
                loading={channels.loading && !channels.data}
                empty={(channels.data?.income_channels?.length ?? 0) === 0}
                emptyMessage="No collections in this range."
                height={200}
              >
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                  <div className="lg:col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-3 content-start">
                    {(channels.data?.income_channels ?? []).map((channel) => (
                      <ChannelCard key={channel.key} channel={channel} />
                    ))}
                  </div>

                  <div className="lg:col-span-1">
                    <DonutChart
                      labels={(channels.data?.income_channels ?? []).map((row) => row.label)}
                      values={(channels.data?.income_channels ?? []).map((row) => row.total)}
                      colors={(channels.data?.income_channels ?? []).map(
                        (row) => CHANNEL_STYLE[row.key]?.color ?? '#adb5bd'
                      )}
                      unit="money"
                      height={220}
                    />
                  </div>
                </div>
              </PanelState>
            </CardBody>
          </Card>
        </Restricted>

        {/* ── Sales Projections & Performance ─────────────────────────── */}
        <Restricted
          require={WIDGET.financialMetrics}
          fallback={<RestrictedPanel title="Financial Projections & Performance" height={160} />}
        >
          {/* No date-range control on this panel, deliberately. Two of the three
              figures are anchored on the calendar — the last seven days and the
              current month — so a picker here would appear to drive numbers it
              cannot change, which is worse than not offering one. The third,
              Daily Average, follows the Headline range and says so in its
              tooltip and its caption. */}
          <Card flush>
            <CardHeader
              title="Sales Projections & Performance"
              subtitle="Daily average, the seven-day rolling average, and the month-to-date projection"
              icon={<TrendingDown size={16} />}
            />
            <CardBody>
              <PanelState
                loading={headline.loading && !headline.data}
                empty={!rolling}
                emptyMessage="Not enough data to calculate projections."
                height={160}
              >
                {/* Expected MRC and Collection Rate were removed from this panel:
                    both are projections *against* a target rather than
                    measurements of what happened, and both drove conversations
                    about the assumption rather than the takings. The three
                    figures left are what was actually collected, at two
                    resolutions, and what that rate implies for the month. */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  {/* Daily Average leads: it is the only one of the three a
                      branch is held to day by day, and it is the divisor the
                      other two are built out of. Anchored on the selected range
                      rather than on the calendar, unlike its two neighbours —
                      which is why it carries the range label underneath and they
                      carry a fixed window. */}
                  <div className={`rounded-lg p-3 border ${isDarkMode ? 'bg-gray-800/40 border-gray-700' : 'bg-gray-50 border-gray-200'}`}>
                    <div className="flex items-center justify-between">
                      <p className={`text-xs font-semibold ${isDarkMode ? 'text-gray-300' : 'text-gray-600'}`}>
                        Daily Average
                      </p>
                      <MetricTooltip
                        title="Daily Average Collection"
                        explanation="Collections over the selected range divided by the number of days in it — every day counts, including the ones with no takings. Unlike the two figures beside it this one follows the Headline range control, so it answers 'what is a normal day' for whatever window is on screen."
                        formula="Income in range ÷ Days in range"
                      />
                    </div>
                    <p className="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">
                      {formatMoney(kpi?.daily_average ?? 0)}
                    </p>
                    <p className={`text-[11px] mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                      {formatMoney(kpi?.income)} over {kpi?.days_elapsed ?? 1}{' '}
                      {pluralise(kpi?.days_elapsed ?? 1, 'day')}
                    </p>
                  </div>

                  <div className={`rounded-lg p-3 border ${isDarkMode ? 'bg-gray-800/40 border-gray-700' : 'bg-gray-50 border-gray-200'}`}>
                    <div className="flex items-center justify-between">
                      <p className={`text-xs font-semibold ${isDarkMode ? 'text-gray-300' : 'text-gray-600'}`}>
                        Weekly Sales Average
                      </p>
                      <MetricTooltip
                        title="Seven-Day Rolling Average"
                        explanation="Collections over the last seven calendar days, divided by seven. Always the past week, never the selected range — and always divided by seven, so a day with no takings stays in the denominator."
                        formula="Last 7 Days' Income ÷ 7"
                      />
                    </div>
                    <p className="text-xl font-bold text-indigo-600 dark:text-indigo-400 mt-1">
                      {formatMoney(rolling?.weekly_average ?? 0)}
                    </p>
                    <p className={`text-[11px] mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                      {rolling?.week_from
                        ? `${formatMoney(rolling.week_income)} since ${formatDate(rolling.week_from)}`
                        : 'past 7 days'}
                    </p>
                  </div>

                  <div className={`rounded-lg p-3 border ${isDarkMode ? 'bg-gray-800/40 border-gray-700' : 'bg-gray-50 border-gray-200'}`}>
                    <div className="flex items-center justify-between">
                      <p className={`text-xs font-semibold ${isDarkMode ? 'text-gray-300' : 'text-gray-600'}`}>
                        Monthly Projected Sales
                      </p>
                      <MetricTooltip
                        title="Monthly Sales Projection"
                        explanation="Collections so far this month, scaled to the whole month. Anchored on the current month rather than the selected range — projecting a month from one day's takings is not a forecast of anything."
                        formula="(Month-to-Date Income ÷ Days Elapsed) × Days in Month"
                      />
                    </div>
                    <p className="text-xl font-bold text-blue-600 dark:text-blue-400 mt-1">
                      {formatMoney(rolling?.projected_monthly ?? 0)}
                    </p>
                    <p className={`text-[11px] mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                      {formatMoney(rolling?.month_income)} over {rolling?.days_elapsed ?? 1}{' '}
                      {pluralise(rolling?.days_elapsed ?? 1, 'day')} of {rolling?.days_in_month ?? 30}
                    </p>
                  </div>
                </div>
              </PanelState>
            </CardBody>
          </Card>
        </Restricted>

        {/* ── OpEx vs CapEx ─────────────────────────────────────────────── */}
        <Restricted
          require={WIDGET.financialOpex}
          fallback={<RestrictedPanel title="OpEx and CapEx" height={220} />}
        >
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <Card flush>
              <CardHeader
                title="Operating Expenses"
                subtitle="Consumed within the period"
                icon={<Building2 size={16} />}
                actions={<WidgetRange state={opexRange} />}
              />
              <ExpenseNatureBody
                nature={opex.data?.opex_capex?.opex}
                loading={opex.loading && !opex.data}
                error={opex.error}
                accent="#0d6efd"
              />
            </Card>

            <Card flush>
              <CardHeader
                title="Capital Expenditures"
                subtitle="Assets that outlive the period"
                icon={<HardDrive size={16} />}
                actions={<WidgetRange state={opexRange} />}
              />
              <ExpenseNatureBody
                nature={opex.data?.opex_capex?.capex}
                loading={opex.loading && !opex.data}
                error={opex.error}
                accent="#fd7e14"
              />
            </Card>
          </div>
        </Restricted>

        {/* "Accounts Payable & Recurring Expenses" stood here and has been
            removed. It was the only writing panel on a reporting page — a
            paid/unpaid toggle against a month — and it made this module two
            things at once: a report of what happened, and a ledger somebody
            maintains. The two have different audiences and different failure
            modes, and a mis-click on a reporting screen should not change a
            payable's state.

            The `payables` block is still computed and still in the API payload,
            and PayablesPanel is still in the tree — nothing about the ledger has
            been deleted, only its place on this page. The `widget.financial.payables`
            permission is likewise still in the catalogue. */}

        {/* ── Four horizons side by side ─────────────────────────────────── */}
        <Restricted
          require={WIDGET.financialRevenue}
          fallback={<RestrictedPanel title="Daily · Weekly · Monthly · Yearly" height={200} />}
        >
          <Card flush>
            <CardHeader
              title="Daily · Weekly · Monthly · Yearly"
              subtitle={`All four measured from ${data?.as_of ?? 'today'}`}
              icon={<CalendarDays size={16} />}
            />
            <Table>
              <Thead>
                <Th width="110px">Period</Th>
                <Th>Date Range</Th>
                <Th align="right" className="text-emerald-600 dark:text-emerald-400">
                  Income
                </Th>
                <Th align="right" className="text-red-500 dark:text-red-400">
                  Expenses
                </Th>
                <Th align="right">Net</Th>
                <Th width="140px" />
              </Thead>
              <tbody>
                <TableState colSpan={6} loading={first} error={error} empty={periods.length === 0} />

                {periods.map((period) => (
                  <Tr key={period.key}>
                    <Td>
                      <span className="inline-flex items-center gap-2">
                        <Dot color={period.accent} />
                        <span className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                          {period.label}
                        </span>
                      </span>
                    </Td>
                    <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                      {period.date_label}
                    </Td>
                    <Td
                      align="right"
                      className="font-bold text-emerald-600 dark:text-emerald-400 tabular-nums"
                    >
                      {formatMoney(period.income)}
                    </Td>
                    <Td align="right" className="font-bold text-red-500 dark:text-red-400 tabular-nums">
                      {formatMoney(period.expenses)}
                    </Td>
                    <Td
                      align="right"
                      className={`font-bold tabular-nums ${
                        period.net > 0
                          ? 'text-emerald-600 dark:text-emerald-400'
                          : period.net < 0
                          ? 'text-red-500 dark:text-red-400'
                          : isDarkMode
                          ? 'text-gray-400'
                          : 'text-gray-500'
                      }`}
                    >
                      {formatMoney(period.net)}
                    </Td>
                    <Td>
                      <div className="flex flex-col gap-1">
                        <span className="flex items-center gap-1.5">
                          <span className="text-[10px] w-12 text-emerald-600 dark:text-emerald-400">
                            Income
                          </span>
                          <Bar
                            pct={maxIncome > 0 ? (period.income / maxIncome) * 100 : 0}
                            color="#198754"
                            width={60}
                            height={5}
                          />
                        </span>
                        <span className="flex items-center gap-1.5">
                          <span className="text-[10px] w-12 text-red-500 dark:text-red-400">
                            Expense
                          </span>
                          <Bar
                            pct={maxExpenses > 0 ? (period.expenses / maxExpenses) * 100 : 0}
                            color="#dc3545"
                            width={60}
                            height={5}
                          />
                        </span>
                      </div>
                    </Td>
                  </Tr>
                ))}
              </tbody>
            </Table>
          </Card>
        </Restricted>

        {/* ── Trends ────────────────────────────────────────────────────── */}
        <Restricted
          require={WIDGET.financialRevenue}
          fallback={<RestrictedPanel title="Income vs Expenses" height={340} />}
        >
          <div className="grid grid-cols-1 xl:grid-cols-5 gap-4">
            <Card flush className="xl:col-span-3">
              <CardHeader
                title="Income vs Expenses"
                subtitle={trend.data?.range_label}
                actions={<WidgetRange state={trendRange} />}
              />
              <CardBody>
                <PanelState
                  loading={trend.loading && !trend.data}
                  empty={(trend.data?.trend.points.length ?? 0) === 0}
                  emptyMessage="No collections or expenses recorded in this range."
                  height={340}
                >
                  <TrendChart points={trend.data?.trend.points ?? []} height={340} />
                </PanelState>
              </CardBody>
            </Card>

            <Card flush className="xl:col-span-2">
              <CardHeader
                title="Expenses by Type"
                subtitle={breakdowns.data?.range_label}
                actions={<WidgetRange state={breakdownRange} />}
              />
              <CardBody>
                <PanelState
                  loading={breakdowns.loading && !breakdowns.data}
                  empty={(breakdowns.data?.by_expense_type.length ?? 0) === 0}
                  emptyMessage="No expenses recorded in this range."
                  height={340}
                >
                  <DonutChart
                    labels={(breakdowns.data?.by_expense_type ?? []).map((row) => row.label)}
                    values={(breakdowns.data?.by_expense_type ?? []).map((row) => row.total)}
                    unit="money"
                    height={340}
                  />
                </PanelState>
              </CardBody>
            </Card>
          </div>

          {/* Day-by-day across the chosen range, distinct from the trend above. */}
          <Card flush>
            <CardHeader
              title="Day by Day"
              subtitle={headline.data?.range_label}
              actions={<WidgetRange state={headlineRange} />}
            />
            <CardBody>
              <PanelState
                loading={first}
                empty={(data?.series.length ?? 0) === 0}
                emptyMessage="No activity on any day in this range."
                height={280}
              >
                <TrendChart points={data?.series ?? []} height={280} />
              </PanelState>
            </CardBody>
          </Card>

          {/* ── Revenue breakdowns ────────────────────────────────────── */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <BreakdownTable
              title="Revenue by Plan"
              labelHeader="Plan"
              countLabel="Payments"
              totalLabel="Revenue"
              rows={breakdowns.data?.by_plan ?? []}
              loading={breakdowns.loading && !breakdowns.data}
              error={breakdowns.error}
              showTotal
            />
            <BreakdownTable
              title="Revenue by Payment Method"
              labelHeader="Method"
              countLabel="Payments"
              totalLabel="Revenue"
              rows={breakdowns.data?.by_method ?? []}
              loading={breakdowns.loading && !breakdowns.data}
              error={breakdowns.error}
              showTotal
            />
          </div>

          {/* "Payment Notes" stood here and has been removed. It grouped
              collections by the free-text remark a cashier typed, which produces
              one row per spelling of the same note — "promo", "Promo", "promo
              adj" — and a total that reconciles against nothing. The remark is
              still on the individual payment for anyone who needs it; what has
              gone is the pretence that it is a category. Removed from the
              printed report as well, so the two agree.

              `payment_notes` remains in the API payload: the drivers compute it
              and dropping the field would break a published response shape for a
              cosmetic gain. */}

          {/* "Collections by Branch" stood here and has been removed as a
              redundant report. GOWISER is a single operating company with no
              branch dimension at all, so the panel drew one row called "All
              accounts" — a pie chart of one wedge, at 100%, restating the total
              already shown three panels above it.

              The `by_branch` block is still in the API payload and still
              computed by the drivers: NETMANAGER genuinely has routers, and
              removing the field would break a published response shape for a
              cosmetic gain. It is the panel that was redundant, not the data. */}
        </Restricted>

        <p className={`text-xs flex items-start gap-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          <CreditCard size={14} className="mt-0.5 flex-shrink-0" />
          <span>
            Income counts payments marked paid. Expenses booked against a longer reporting period
            (monthly, yearly) are excluded from shorter views, so a day never carries a month's
            rent — the headline range is treated as{' '}
            <strong>{data?.expense_period ?? 'daily'}</strong>. Each widget above carries its own
            date range and they move independently.
          </span>
        </p>

        <PrintReportOverlay
          open={printOpen}
          onClose={() => setPrintOpen(false)}
          source={source || activeSource}
          // Month-to-date, not the headline widget's range. The page now opens
          // on Daily, and a printed financial report covering a single quiet
          // morning is a correctly-built document with nothing in it — which
          // reads as broken. The overlay carries its own date controls, so this
          // is a starting point rather than a decision made for the user.
          dateFrom={printRange.range.from}
          dateTo={printRange.range.to}
          branch={filters.branch}
          preparedBy={user.full_name || user.username || user.email || ''}
          preparedByRole={user.role || ''}
        />
      </ReportingPage>
    </div>
  );
};

/** One collection channel: its total, its share, and what fed it. */
const ChannelCard: React.FC<{ channel: IncomeChannel }> = ({ channel }) => {
  const isDarkMode = useTheme();
  const style = CHANNEL_STYLE[channel.key] ?? CHANNEL_STYLE.other;

  return (
    <div
      className={`rounded-xl border p-3 ${
        isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'
      }`}
      style={{ borderLeftWidth: '4px', borderLeftColor: style.color }}
    >
      <div
        className={`flex items-center gap-1.5 text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}
      >
        <span style={{ color: style.color }}>{style.icon}</span>
        <span>{channel.label}</span>
      </div>
      <p className="mt-1 text-2xl font-bold tracking-tight tabular-nums truncate">
        {formatMoney(channel.total)}
      </p>
      <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        {pluralise(channel.count, 'payment')} · {formatPercent(channel.share_pct)} of income
      </p>
      {channel.methods.length > 0 && (
        <p
          className={`text-[11px] mt-1 truncate ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}
          title={channel.methods.join(', ')}
        >
          {channel.methods.join(', ')}
        </p>
      )}
    </div>
  );
};

/** The body of an OpEx or CapEx card: the total, then what makes it up. */
const ExpenseNatureBody: React.FC<{
  nature?: { label: string; total: number; count: number; share_pct: number; rows: { label: string; count: number; total: number }[] };
  loading: boolean;
  error: string | null;
  accent: string;
}> = ({ nature, loading, error, accent }) => {
  const isDarkMode = useTheme();

  const largest = (nature?.rows ?? []).reduce((max, row) => Math.max(max, row.total), 0);

  return (
    <CardBody>
      <PanelState
        loading={loading}
        error={error}
        empty={!nature || nature.rows.length === 0}
        emptyMessage="Nothing booked in this range."
        height={200}
      >
        <div className="flex items-baseline justify-between gap-2 mb-3">
          <p className="text-3xl font-bold tabular-nums" style={{ color: accent }}>
            {formatMoney(nature?.total)}
          </p>
          <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            {formatPercent(nature?.share_pct)} of spend · {pluralise(nature?.count ?? 0, 'record')}
          </p>
        </div>

        <div className="space-y-2">
          {(nature?.rows ?? []).slice(0, 8).map((row) => (
            <div key={row.label}>
              <div className="flex items-baseline justify-between gap-2 mb-1">
                <span className={`text-sm truncate ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                  {row.label}
                </span>
                <span className="text-sm font-semibold whitespace-nowrap tabular-nums">
                  {formatMoney(row.total)}
                </span>
              </div>
              {/* Scaled against the largest row in this card, so the bars
                  compare types within one nature rather than across both. */}
              <span
                className={`block h-1.5 rounded-full overflow-hidden ${
                  isDarkMode ? 'bg-gray-700' : 'bg-gray-200'
                }`}
              >
                <span
                  className="block h-full rounded-full transition-all duration-500"
                  style={{
                    width: `${largest > 0 ? (row.total / largest) * 100 : 0}%`,
                    backgroundColor: accent,
                  }}
                />
              </span>
            </div>
          ))}
        </div>
      </PanelState>
    </CardBody>
  );
};

export default Financial;
