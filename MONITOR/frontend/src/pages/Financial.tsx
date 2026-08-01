import React from 'react';
import {
  ArrowDownCircle,
  ArrowUpCircle,
  Banknote,
  Briefcase,
  CalendarDays,
  CreditCard,
  Printer,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import PeriodTabs from '../components/reporting/PeriodTabs';
import { AccentCard, MiniStat } from '../components/reporting/StatCard';
import BreakdownTable from '../components/reporting/BreakdownTable';
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
import PrintReportOverlay from '../components/print/PrintReportOverlay';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { useMonitorStore } from '../store/monitorStore';
import { reportingService } from '../services/reportingService';
import { FinancialData, Granularity } from '../types/reporting';
import { UserData } from '../types/api';
import { formatMoney, formatNumber, formatPercent, pluralise } from '../utils/format';

interface FinancialProps {
  refreshToken: number;
  user: UserData;
}

const TREND_SCOPE: Record<Granularity, string> = {
  daily: 'Last 30 Days',
  weekly: 'Last 12 Weeks',
  monthly: 'Last 12 Months',
  yearly: 'Last 10 Years',
};

/**
 * Financial — money in, money out, and everything behind both.
 *
 * Three period controls, independent by design: the date range drives the
 * headline figures and breakdowns, the trend tabs drive the long-horizon chart,
 * and the branch panel has its own window. Someone comparing this month against
 * the twelve-month trend needs all three at once.
 */
const Financial: React.FC<FinancialProps> = ({ refreshToken, user }) => {
  const isDarkMode = useTheme();
  const activeSource = useMonitorStore((state) => state.activeSource);
  const { filters, update, reset, branches, databases } = useSectionFilters('financial');

  const [printOpen, setPrintOpen] = React.useState(false);

  const { data, loading, error, source, sourceLabel, substituted } = useReportingSection<FinancialData>(
    reportingService.getFinancial,
    filters,
    refreshToken
  );

  const kpi = data?.kpi;
  const first = loading && !data;
  const surplus = (kpi?.net ?? 0) >= 0;

  // Bars are scaled within their own measure, so income bars compare income
  // across horizons and expense bars compare expenses. One shared maximum would
  // flatten expenses into invisibility whenever income is larger, which is most
  // of the time.
  const periods = data?.periods ?? [];
  const maxIncome = periods.reduce((max, period) => Math.max(max, period.income), 0);
  const maxExpenses = periods.reduce((max, period) => Math.max(max, period.expenses), 0);

  return (
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
          <Button
            variant="primary"
            icon={<Printer size={14} />}
            onClick={() => setPrintOpen(true)}
            disabled={!data}
          >
            Print Report
          </Button>
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
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <AccentCard
          label="Income"
          value={formatMoney(kpi?.income)}
          tone="success"
          icon={<ArrowDownCircle size={15} />}
          caption={kpi ? pluralise(kpi.income_count, 'payment') : undefined}
          loading={first}
        >
          {/* Office vs portal is the split branch managers are asked about
              daily, so it lives inside the Income card rather than a panel of
              its own further down the page. */}
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
            <div className={`mt-3 pt-3 border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}>
              <p className={`text-xs mb-1.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                Office collections by charge type
              </p>
              {kpi.office_by_type.map((row) => (
                <div key={row.label} className="flex items-baseline justify-between gap-2 text-xs py-0.5">
                  <span className={`truncate ${isDarkMode ? 'text-gray-300' : 'text-gray-600'}`}>
                    {row.label}
                    <span className={isDarkMode ? 'text-gray-500' : 'text-gray-400'}>
                      {' '}
                      ×{formatNumber(row.count)}
                    </span>
                  </span>
                  <span className="font-semibold whitespace-nowrap">{formatMoney(row.total)}</span>
                </div>
              ))}
            </div>
          )}
        </AccentCard>

        <AccentCard
          label="Expenses"
          value={formatMoney(kpi?.expenses)}
          tone="danger"
          icon={<ArrowUpCircle size={15} />}
          caption={kpi ? pluralise(kpi.expenses_count, 'record') : undefined}
          loading={first}
        />

        <AccentCard
          label="Net"
          value={formatMoney(kpi?.net)}
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

      {/* ── Secondary measures ────────────────────────────────────────── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <SmallStat
          label="Collection rate"
          value={kpi ? formatPercent(kpi.collection_rate) : '—'}
          caption={kpi ? `against ${formatMoney(kpi.expected_mrc)} expected MRC` : undefined}
        />
        <SmallStat
          label="Average payment"
          value={formatMoney(kpi?.average_payment)}
          caption={kpi ? `largest ${formatMoney(kpi.largest_payment)}` : undefined}
        />
        <SmallStat
          label="Expense scope"
          value={data?.expense_period ?? '—'}
          caption="bookings of this horizon and shorter"
        />
        <SmallStat
          label="Period"
          value={data?.range_label ?? '—'}
          caption={`as of ${data?.as_of ?? '—'}`}
        />
      </div>

      {/* ── Four horizons side by side ─────────────────────────────────── */}
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
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>{period.date_label}</Td>
                <Td align="right" className="font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">
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
                      <span className="text-[10px] w-12 text-emerald-600 dark:text-emerald-400">Income</span>
                      <Bar
                        pct={maxIncome > 0 ? (period.income / maxIncome) * 100 : 0}
                        color="#198754"
                        width={60}
                        height={5}
                      />
                    </span>
                    <span className="flex items-center gap-1.5">
                      <span className="text-[10px] w-12 text-red-500 dark:text-red-400">Expense</span>
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

      {/* ── Trends ────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 xl:grid-cols-5 gap-4">
        <Card flush className="xl:col-span-3">
          <CardHeader
            title={`Income vs Expenses (${TREND_SCOPE[filters.period]})`}
            actions={
              <PeriodTabs
                value={filters.period}
                onChange={(period) => update({ period })}
                size="sm"
              />
            }
          />
          <CardBody>
            <PanelState
              loading={first}
              empty={(data?.trend.points.length ?? 0) === 0}
              emptyMessage="No collections or expenses recorded in this range."
              height={340}
            >
              <TrendChart points={data?.trend.points ?? []} height={340} />
            </PanelState>
          </CardBody>
        </Card>

        <Card flush className="xl:col-span-2">
          <CardHeader title="Expenses by Type" subtitle={data?.range_label} />
          <CardBody>
            <PanelState
              loading={first}
              empty={(data?.by_expense_type.length ?? 0) === 0}
              emptyMessage="No expenses recorded in this range."
              height={340}
            >
              <DonutChart
                labels={(data?.by_expense_type ?? []).map((row) => row.label)}
                values={(data?.by_expense_type ?? []).map((row) => row.total)}
                unit="money"
                height={340}
              />
            </PanelState>
          </CardBody>
        </Card>
      </div>

      {/* Day-by-day across the chosen range, distinct from the trend above. */}
      <Card flush>
        <CardHeader title="Day by Day" subtitle={data?.range_label} />
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

      {/* ── Revenue breakdowns ────────────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <BreakdownTable
          title="Revenue by Plan"
          labelHeader="Plan"
          countLabel="Payments"
          totalLabel="Revenue"
          rows={data?.by_plan ?? []}
          loading={first}
          error={error}
          showTotal
        />
        <BreakdownTable
          title="Revenue by Payment Method"
          labelHeader="Method"
          countLabel="Payments"
          totalLabel="Revenue"
          rows={data?.by_method ?? []}
          loading={first}
          error={error}
          showTotal
        />
      </div>

      <BreakdownTable
        title="Payment Notes"
        labelHeader="Note"
        countLabel="Subscribers"
        totalLabel="Amount"
        rows={data?.payment_notes ?? []}
        loading={first}
        error={error}
        emptyMessage="No payments with notes for this period."
      />

      {/* ── Branch comparison ─────────────────────────────────────────── */}
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

      {/* ── Recent activity ──────────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="Recent Payments"
          subtitle="Most recently recorded, newest first"
          icon={<Banknote size={16} />}
        />
        <Table>
          <Thead>
            <Th>OR No.</Th>
            <Th>Subscriber</Th>
            <Th>Account #</Th>
            <Th>Method</Th>
            <Th align="right">Amount</Th>
            <Th align="right">Status</Th>
          </Thead>
          <tbody>
            <TableState
              colSpan={6}
              loading={first}
              error={error}
              empty={(data?.recent_payments.length ?? 0) === 0}
              emptyMessage="No payments have been recorded yet."
            />

            {(data?.recent_payments ?? []).map((payment) => (
              <Tr key={payment.id}>
                <Td className="font-mono text-xs text-blue-600 dark:text-blue-400">
                  {payment.or_number || '—'}
                </Td>
                <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {payment.subscriber || '—'}
                </Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {payment.account_number || '—'}
                </Td>
                <Td className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                  {payment.method || '—'}
                </Td>
                <Td align="right" className="font-semibold">
                  {formatMoney(payment.amount)}
                </Td>
                <Td align="right">
                  <span
                    className={`text-xs font-semibold capitalize ${
                      payment.status === 'paid'
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : payment.status === 'pending'
                        ? 'text-amber-500'
                        : 'text-red-500'
                    }`}
                  >
                    {payment.status || '—'}
                  </span>
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>

      <p className={`text-xs flex items-start gap-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        <CreditCard size={14} className="mt-0.5 flex-shrink-0" />
        <span>
          Income counts payments marked paid. Expenses booked against a longer reporting period
          (monthly, yearly) are excluded from shorter views, so a day never carries a month's rent —
          this range is treated as <strong>{data?.expense_period ?? 'daily'}</strong>.
        </span>
      </p>

      <PrintReportOverlay
        open={printOpen}
        onClose={() => setPrintOpen(false)}
        // Prints from whichever source answered this page, not the selected
        // one, so the document matches the figures above it.
        source={source || activeSource}
        dateFrom={filters.dateFrom}
        dateTo={filters.dateTo}
        branch={filters.branch}
        preparedBy={user.full_name || user.username || user.email || ''}
        preparedByRole={user.role || ''}
      />
    </ReportingPage>
  );
};

/** A compact measure with no accent rule — the second tier of the page. */
const SmallStat: React.FC<{ label: string; value: React.ReactNode; caption?: React.ReactNode }> = ({
  label,
  value,
  caption,
}) => {
  const isDarkMode = useTheme();

  return (
    <Card>
      <p className={`text-xs uppercase tracking-wide ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        {label}
      </p>
      <p className="text-xl font-bold mt-1 truncate capitalize">{value}</p>
      {caption && (
        <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>{caption}</p>
      )}
    </Card>
  );
};

export default Financial;
