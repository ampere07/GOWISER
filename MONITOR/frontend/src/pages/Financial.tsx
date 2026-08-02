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
import { AccentCard, MiniStat } from '../components/reporting/StatCard';
import BreakdownTable from '../components/reporting/BreakdownTable';
import PayablesPanel from '../components/reporting/PayablesPanel';
import RouterReportsPanel from '../components/reporting/RouterReportsPanel';
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
import { Restricted, RestrictedPanel, MaskedValue } from '../components/rbac/Restricted';
import PrintReportOverlay from '../components/print/PrintReportOverlay';
import { useReportingSection } from '../hooks/useReportingSection';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { useMonitorStore } from '../store/monitorStore';
import { reportingService } from '../services/reportingService';
import { ExecutiveMetric, FinancialData, IncomeChannel } from '../types/reporting';
import { ACTION, WIDGET } from '../types/rbac';
import { UserData } from '../types/api';
import { formatMoney, formatNumber, formatPercent, pluralise } from '../utils/format';

interface FinancialProps {
  refreshToken: number;
  user: UserData;
}

/** Icon and accent per collection channel, so a channel reads at a glance. */
const CHANNEL_STYLE: Record<string, { icon: React.ReactNode; color: string }> = {
  cash: { icon: <Banknote size={15} />, color: '#198754' },
  pnb: { icon: <Landmark size={15} />, color: '#0d6efd' },
  xendit: { icon: <CreditCard size={15} />, color: '#7c3aed' },
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

  // One range per widget. Headline, trend, breakdowns and payables all default to
  // month-to-date, so on first load they share a cache key and cost one request
  // between them; only a widget someone moves pays for itself.
  const headlineRange = useWidgetRange('monthly');
  const trendRange = useWidgetRange('monthly');
  const channelsRange = useWidgetRange('monthly');
  const metricsRange = useWidgetRange('monthly');
  const opexRange = useWidgetRange('monthly');
  const payablesRange = useWidgetRange('monthly');
  const breakdownRange = useWidgetRange('monthly');

  const headline = useReportingSection<FinancialData>(
    reportingService.getFinancial,
    filters,
    refreshToken,
    { dateFrom: headlineRange.range.from, dateTo: headlineRange.range.to }
  );

  const trend = useReportingSection<FinancialData>(reportingService.getFinancial, filters, refreshToken, {
    dateFrom: trendRange.range.from,
    dateTo: trendRange.range.to,
    period: trendRange.granularity,
  });

  const channels = useReportingSection<FinancialData>(
    reportingService.getFinancial,
    filters,
    refreshToken,
    { dateFrom: channelsRange.range.from, dateTo: channelsRange.range.to }
  );

  const metrics = useReportingSection<FinancialData>(
    reportingService.getFinancial,
    filters,
    refreshToken,
    { dateFrom: metricsRange.range.from, dateTo: metricsRange.range.to }
  );

  const opex = useReportingSection<FinancialData>(reportingService.getFinancial, filters, refreshToken, {
    dateFrom: opexRange.range.from,
    dateTo: opexRange.range.to,
  });

  const payables = useReportingSection<FinancialData>(
    reportingService.getFinancial,
    filters,
    refreshToken,
    { dateFrom: payablesRange.range.from, dateTo: payablesRange.range.to }
  );

  const breakdowns = useReportingSection<FinancialData>(
    reportingService.getFinancial,
    filters,
    refreshToken,
    { dateFrom: breakdownRange.range.from, dateTo: breakdownRange.range.to }
  );

  const { data, loading, error, source, sourceLabel, substituted } = headline;

  const kpi = data?.kpi;
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

  return (
    // `financial-module` scopes the monochrome print rules in index.css. A
    // financial printout goes into a folder and gets signed, so it prints as
    // black text on white paper regardless of the theme on screen.
    <div className="financial-module">
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
            // Printing puts the ledger on paper and out of the building, which is
            // a stronger act than reading it — so it is a separate grant, and the
            // route enforces the same one.
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
              {/* Office vs portal is the split branch managers are asked about
                  daily, so it lives inside the Income card rather than a panel
                  of its own further down the page. */}
              {seesRevenue && (
                <>
                  <div className="grid grid-cols-2 gap-2">
                    <MiniStat
                      label="Office Collection"
                      value={formatMoney(kpi?.office_income)}
                      caption={kpi ? pluralise(kpi.office_count, 'payment') : undefined}
                      tone="success"
                    />
                    <MiniStat
                      label="Portal Collection"
                      value={formatMoney(kpi?.portal_income)}
                      caption={kpi ? pluralise(kpi.portal_count, 'payment') : undefined}
                      tone="info"
                    />
                  </div>

                  {kpi && kpi.office_by_type.length > 0 && (
                    <div
                      className={`mt-3 pt-3 border-t ${
                        isDarkMode ? 'border-gray-800' : 'border-gray-200'
                      }`}
                    >
                      <p className={`text-xs mb-1.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                        Office collections by charge type
                      </p>
                      {kpi.office_by_type.map((row) => (
                        <div
                          key={row.label}
                          className="flex items-baseline justify-between gap-2 text-xs py-0.5"
                        >
                          <span className={`truncate ${isDarkMode ? 'text-gray-300' : 'text-gray-600'}`}>
                            {row.label}
                            <span className={isDarkMode ? 'text-gray-500' : 'text-gray-400'}>
                              {' '}
                              ×{formatNumber(row.count)}
                            </span>
                          </span>
                          <span className="font-semibold whitespace-nowrap">
                            {formatMoney(row.total)}
                          </span>
                        </div>
                      ))}
                    </div>
                  )}
                </>
              )}
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

        {/* ── Income channels: Cash · PNB · Xendit ───────────────────────── */}
        <Restricted
          require={WIDGET.financialChannels}
          fallback={<RestrictedPanel title="Income Channels" height={200} />}
        >
          <Card flush>
            <CardHeader
              title="Income Channels"
              subtitle="Cash over the counter, PNB deposits, and Xendit online collections"
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

        {/* ── Executive metrics ─────────────────────────────────────────── */}
        <Restricted
          require={WIDGET.financialMetrics}
          fallback={<RestrictedPanel title="Executive Financial Metrics" height={160} />}
        >
          <Card flush>
            <CardHeader
              title="Executive Metrics"
              subtitle="Forward-looking measures — each states the assumption behind it"
              icon={<TrendingDown size={16} />}
              actions={<WidgetRange state={metricsRange} />}
            />
            <CardBody>
              <PanelState
                loading={metrics.loading && !metrics.data}
                empty={!metrics.data?.executive_metrics}
                emptyMessage="Not enough data to project these measures."
                height={160}
              >
                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                  <MetricTile
                    metric={metrics.data?.executive_metrics?.prospective_revenue}
                    format="money"
                  />
                  <MetricTile metric={metrics.data?.executive_metrics?.arpu} format="money" />
                  <MetricTile
                    metric={metrics.data?.executive_metrics?.collection_efficiency}
                    format="percent"
                  />
                  <MetricTile
                    metric={metrics.data?.executive_metrics?.projected_churn_loss}
                    format="money"
                    tone="danger"
                  />
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

        {/* ── Accounts payable ──────────────────────────────────────────── */}
        <Restricted
          require={WIDGET.financialPayables}
          fallback={<RestrictedPanel title="Accounts Payable" height={240} />}
        >
          <PayablesPanel
            ledger={payables.data?.payables ?? null}
            loading={payables.loading && !payables.data}
            error={payables.error}
            onChanged={() => payablesRange.setPreset(payablesRange.preset)}
            actions={<WidgetRange state={payablesRange} />}
          />
        </Restricted>

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

          <BreakdownTable
            title="Payment Notes"
            labelHeader="Note"
            countLabel="Subscribers"
            totalLabel="Amount"
            rows={breakdowns.data?.payment_notes ?? []}
            loading={breakdowns.loading && !breakdowns.data}
            error={breakdowns.error}
            emptyMessage="No payments with notes for this period."
          />

          {/* ── Branch comparison ─────────────────────────────────────── */}
          <RouterReportsPanel
            rows={data?.by_branch.rows ?? []}
            label={data?.by_branch.label ?? ''}
            period={filters.branchPeriod}
            onPeriodChange={(branchPeriod) => update({ branchPeriod })}
            year={filters.branchYear}
            years={data?.by_branch.years ?? []}
            onYearChange={(branchYear) => update({ branchYear })}
            loading={first}
            error={error}
          />
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
          // Prints from whichever source answered this page, not the selected
          // one, so the document matches the figures above it, and over the
          // headline widget's range — the one the page is headed with.
          source={source || activeSource}
          dateFrom={headlineRange.range.from}
          dateTo={headlineRange.range.to}
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
        // The raw methods that rolled into this channel, so an unexpected total
        // can be traced without opening the source system.
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

/**
 * One executive measure, with its basis printed underneath.
 *
 * The basis line is not decoration. Three of the four are projections, and a
 * projection shown with the same authority as a measurement is the commonest way
 * a dashboard misleads — so the assumption sits with the number rather than in a
 * footnote nobody reads.
 */
const MetricTile: React.FC<{
  metric?: ExecutiveMetric;
  format: 'money' | 'percent';
  tone?: 'danger';
}> = ({ metric, format, tone }) => {
  const isDarkMode = useTheme();

  const value =
    metric?.value === null || metric?.value === undefined
      ? '—'
      : format === 'money'
      ? formatMoney(metric.value)
      : formatPercent(metric.value);

  return (
    <div className={`rounded-lg px-3 py-2.5 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}>
      <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        {metric?.label ?? '—'}
      </p>
      <p
        className={`text-xl font-bold tabular-nums truncate mt-0.5 ${
          tone === 'danger' ? 'text-red-500 dark:text-red-400' : ''
        }`}
      >
        {value}
      </p>
      <p className={`text-[11px] mt-1 leading-snug ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        {metric?.basis ?? ''}
      </p>
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
