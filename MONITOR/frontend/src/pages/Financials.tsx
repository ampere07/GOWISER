import React, { useEffect, useMemo, useState } from 'react';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  PointElement,
  LineElement,
  ArcElement,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js';
import { Bar, Doughnut } from 'react-chartjs-2';
import { Banknote, Building2, Receipt, TrendingDown, TrendingUp, Wallet } from 'lucide-react';
import PageShell from '../components/common/PageShell';
import Panel from '../components/common/Panel';
import MetricCard, { formatCurrency } from '../components/common/MetricCard';
import { monitorService } from '../services/monitorService';
import { useTheme } from '../hooks/useTheme';
import { usePalette } from '../hooks/usePalette';
import { useMonitorStore } from '../store/monitorStore';
import {
  Branch,
  FinancialPeriod,
  Financials as FinancialsData,
  FinancialSlice,
  SourcedResponse,
} from '../types/monitor';

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  PointElement,
  LineElement,
  ArcElement,
  Tooltip,
  Legend,
  Filler
);

interface FinancialsProps {
  refreshToken: number;
}

const PERIODS: { key: FinancialPeriod; label: string }[] = [
  { key: 'daily', label: 'Day' },
  { key: 'weekly', label: 'Week' },
  { key: 'monthly', label: 'Month' },
  { key: 'yearly', label: 'Year' },
];

const INCOME_COLOR = '#10b981';
const EXPENSE_COLOR = '#ef4444';
const NET_COLOR = '#3b82f6';

const Financials: React.FC<FinancialsProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const palette = usePalette();
  const activeSource = useMonitorStore((state) => state.activeSource);

  const [period, setPeriod] = useState<FinancialPeriod>('monthly');
  const [branch, setBranch] = useState<string | null>(null);
  const [asOf, setAsOf] = useState<string | null>(null);

  const [branches, setBranches] = useState<Branch[]>([]);
  const [data, setData] = useState<FinancialsData | null>(null);
  const [sourceLabel, setSourceLabel] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!activeSource) return;

    let cancelled = false;

    monitorService
      .getBranches(activeSource)
      .then((result) => {
        if (!cancelled) setBranches(result);
      })
      .catch((err) => console.error('Failed to load branches:', err));

    return () => {
      cancelled = true;
    };
  }, [activeSource]);

  // Selecting a branch that the newly-chosen source does not have would
  // silently return empty figures, so reset the filter when the source changes.
  useEffect(() => {
    setBranch(null);
  }, [activeSource]);

  useEffect(() => {
    if (!activeSource) return;

    let cancelled = false;
    setLoading(true);

    monitorService
      .getFinancials(activeSource, period, branch, asOf)
      .then((result: SourcedResponse<FinancialsData>) => {
        if (cancelled) return;
        setData(result.data);
        setSourceLabel(result.source_label);
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;
        console.error('Financials fetch failed:', err);
        setError(
          err?.response?.data?.message || 'Unable to load financials. The source database may be unreachable.'
        );
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [activeSource, period, branch, asOf, refreshToken]);

  const kpi = data?.kpi;
  const surplus = (kpi?.net ?? 0) >= 0;

  const grid = isDarkMode ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)';
  const tick = isDarkMode ? 'rgba(255,255,255,0.5)' : 'rgba(0,0,0,0.5)';

  const tooltipStyle = {
    backgroundColor: isDarkMode ? '#1e293b' : '#ffffff',
    titleColor: isDarkMode ? '#f1f5f9' : '#0f172a',
    bodyColor: isDarkMode ? '#f1f5f9' : '#0f172a',
    borderColor: isDarkMode ? '#334155' : '#e2e8f0',
    borderWidth: 1,
    padding: 12,
    cornerRadius: 12,
  };

  /**
   * Income and expenses as bars, net as a line on top — the shape the source
   * dashboard uses, kept so the two systems read the same way.
   */
  const trendOptions = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index' as const, intersect: false },
      plugins: {
        legend: {
          display: true,
          position: 'top' as const,
          labels: { color: tick, boxWidth: 12, padding: 16, font: { size: 11 } },
        },
        tooltip: {
          ...tooltipStyle,
          callbacks: {
            label: (ctx: any) => ` ${ctx.dataset.label}: ${formatCurrency(ctx.raw)}`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: grid },
          ticks: {
            color: tick,
            font: { size: 10 },
            callback: (value: any) => formatCurrency(Number(value)),
          },
        },
        x: { grid: { display: false }, ticks: { color: tick, font: { size: 10 } } },
      },
    }),
    [grid, tick, isDarkMode] // eslint-disable-line react-hooks/exhaustive-deps
  );

  const trendData = useMemo(() => {
    const series = data?.series ?? [];

    return {
      labels: series.map((point) => point.label),
      datasets: [
        {
          label: 'Income',
          data: series.map((point) => point.income),
          backgroundColor: `${INCOME_COLOR}b3`,
          borderColor: INCOME_COLOR,
          borderWidth: 1,
          borderRadius: 4,
          order: 2,
        },
        {
          label: 'Expenses',
          data: series.map((point) => point.expenses),
          backgroundColor: `${EXPENSE_COLOR}b3`,
          borderColor: EXPENSE_COLOR,
          borderWidth: 1,
          borderRadius: 4,
          order: 3,
        },
        {
          label: 'Net',
          data: series.map((point) => point.net),
          type: 'line' as const,
          borderColor: NET_COLOR,
          backgroundColor: `${NET_COLOR}14`,
          borderWidth: 2,
          pointRadius: 3,
          pointBackgroundColor: NET_COLOR,
          fill: false,
          tension: 0.3,
          order: 1,
        },
      ],
    };
  }, [data]);

  const doughnutOptions = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom' as const,
          labels: { color: tick, boxWidth: 10, padding: 10, font: { size: 10 } },
        },
        tooltip: {
          ...tooltipStyle,
          callbacks: {
            label: (ctx: any) => ` ${ctx.label}: ${formatCurrency(ctx.raw)}`,
          },
        },
      },
    }),
    [tick, isDarkMode] // eslint-disable-line react-hooks/exhaustive-deps
  );

  // Same look, but the plan mix counts subscribers rather than pesos.
  const countDoughnutOptions = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom' as const,
          labels: { color: tick, boxWidth: 10, padding: 10, font: { size: 10 } },
        },
        tooltip: {
          ...tooltipStyle,
          callbacks: {
            label: (ctx: any) => ` ${ctx.label}: ${Number(ctx.raw).toLocaleString()} subscribers`,
          },
        },
      },
    }),
    [tick, isDarkMode] // eslint-disable-line react-hooks/exhaustive-deps
  );

  const sliceColors = [
    palette.primary,
    '#0ea5e9',
    '#f59e0b',
    '#ec4899',
    '#8b5cf6',
    '#14b8a6',
    '#f97316',
    '#64748b',
  ];

  const sliceData = (slices: FinancialSlice[] | undefined) => ({
    labels: (slices ?? []).map((slice) => slice.label),
    datasets: [
      {
        data: (slices ?? []).map((slice) => slice.total),
        backgroundColor: sliceColors,
        borderWidth: 0,
      },
    ],
  });

  const controlClass = `text-sm rounded-lg px-3 py-1.5 border outline-none cursor-pointer ${
    isDarkMode ? 'bg-gray-900 border-gray-700 text-gray-200' : 'bg-white border-gray-300 text-gray-800'
  }`;

  const subtitle = data
    ? `${sourceLabel} · ${data.branch_label} · ${data.period_label}`
    : 'Loading...';

  return (
    <PageShell title="Financials" subtitle={subtitle} error={error}>
      {/* ── Filters ─────────────────────────────────────────── */}
      <div className="flex flex-wrap items-center gap-3">
        <div className={`inline-flex rounded-lg border overflow-hidden ${isDarkMode ? 'border-gray-700' : 'border-gray-300'}`}>
          {PERIODS.map((option) => {
            const active = option.key === period;
            return (
              <button
                key={option.key}
                onClick={() => setPeriod(option.key)}
                className={`px-4 py-1.5 text-sm font-medium transition-colors ${
                  active ? 'text-white' : isDarkMode ? 'text-gray-300 hover:bg-gray-800' : 'text-gray-700 hover:bg-gray-100'
                }`}
                style={active ? { backgroundColor: palette.primary } : {}}
              >
                {option.label}
              </button>
            );
          })}
        </div>

        {branches.length > 0 && (
          <select
            value={branch ?? 'all'}
            onChange={(e) => setBranch(e.target.value === 'all' ? null : e.target.value)}
            className={controlClass}
          >
            <option value="all">All branches</option>
            {branches.map((item) => (
              <option key={item.id} value={item.id}>
                {item.label}
              </option>
            ))}
          </select>
        )}

        <input
          type="date"
          value={asOf ?? ''}
          onChange={(e) => setAsOf(e.target.value || null)}
          className={controlClass}
          title="Report as of this date"
        />

        {asOf && (
          <button
            onClick={() => setAsOf(null)}
            className={`text-xs underline ${isDarkMode ? 'text-slate-400' : 'text-slate-500'}`}
          >
            back to today
          </button>
        )}
      </div>

      {/* ── Headline KPIs ───────────────────────────────────── */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
        <MetricCard
          title="Income"
          value={kpi?.income}
          currency
          icon={<Banknote size={20} />}
          iconColor="text-emerald-500"
          caption={kpi ? `${kpi.income_count.toLocaleString()} payments` : undefined}
          loading={loading}
        />
        <MetricCard
          title="Expenses"
          value={kpi?.expenses}
          currency
          icon={<Receipt size={20} />}
          iconColor="text-red-500"
          caption={kpi ? `${kpi.expenses_count.toLocaleString()} entries` : undefined}
          loading={loading}
        />
        <MetricCard
          title={surplus ? 'Net Surplus' : 'Net Deficit'}
          value={kpi?.net}
          currency
          icon={surplus ? <TrendingUp size={20} /> : <TrendingDown size={20} />}
          iconColor={surplus ? 'text-emerald-500' : 'text-red-500'}
          caption={
            kpi?.margin_pct !== null && kpi?.margin_pct !== undefined
              ? `${kpi.margin_pct}% margin`
              : 'no income this period'
          }
          loading={loading}
        />
        <MetricCard
          title="Active Subscribers"
          value={data?.subscribers.active}
          icon={<Building2 size={20} />}
          iconColor="text-indigo-500"
          caption={data ? `${data.subscribers.total.toLocaleString()} on file` : undefined}
          loading={loading}
        />
      </div>

      {/* ── Where the money came in ─────────────────────────── */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Panel title="Office vs Portal collections" scope={data?.period_label}>
          {kpi ? (
            <div className="space-y-4">
              {[
                {
                  name: 'Over the counter',
                  amount: kpi.office_income,
                  count: kpi.office_count,
                  color: palette.primary,
                },
                {
                  name: 'Online portal',
                  amount: kpi.portal_income,
                  count: kpi.portal_count,
                  color: '#0ea5e9',
                },
              ].map((row) => {
                const pct = kpi.income > 0 ? (row.amount / kpi.income) * 100 : 0;

                return (
                  <div key={row.name}>
                    <div className="flex items-baseline justify-between mb-1">
                      <span className={`text-sm font-medium ${isDarkMode ? 'text-slate-300' : 'text-slate-700'}`}>
                        {row.name}
                      </span>
                      <span className="text-sm font-bold">{formatCurrency(row.amount)}</span>
                    </div>
                    <div className={`h-2 rounded-full overflow-hidden ${isDarkMode ? 'bg-gray-800' : 'bg-gray-200'}`}>
                      <div
                        className="h-full rounded-full transition-all duration-500"
                        style={{ width: `${pct}%`, backgroundColor: row.color }}
                      />
                    </div>
                    <div className={`mt-1 text-[11px] ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}>
                      {row.count.toLocaleString()} payments · {Math.round(pct)}% of collections
                    </div>
                  </div>
                );
              })}
            </div>
          ) : (
            <p className={isDarkMode ? 'text-slate-500' : 'text-slate-500'}>Loading...</p>
          )}
        </Panel>

        <Panel title="Income by charge type" scope={data?.period_label}>
          {data && data.by_payment_type.length > 0 ? (
            <div className="h-[220px]">
              <Doughnut options={doughnutOptions} data={sliceData(data.by_payment_type)} />
            </div>
          ) : (
            <p className={isDarkMode ? 'text-slate-500' : 'text-slate-500'}>
              {loading ? 'Loading...' : 'No collections in this period.'}
            </p>
          )}
        </Panel>
      </div>

      {/* ── The main trend ──────────────────────────────────── */}
      <Panel
        title="Income · Expenses · Net"
        scope={
          period === 'daily'
            ? 'Last 30 days'
            : period === 'weekly'
            ? 'Last 12 weeks'
            : period === 'yearly'
            ? 'Last 10 years'
            : 'Last 12 months'
        }
      >
        <div className="h-[340px] relative">
          <Bar options={trendOptions as any} data={trendData as any} />
        </div>
      </Panel>

      {/* ── Where the money went ────────────────────────────── */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Panel title="Expenses by type" scope={data?.period_label}>
          {data && data.by_expense_type.length > 0 ? (
            <div className="h-[260px]">
              <Doughnut options={doughnutOptions} data={sliceData(data.by_expense_type)} />
            </div>
          ) : (
            <p className={isDarkMode ? 'text-slate-500' : 'text-slate-500'}>
              {loading ? 'Loading...' : 'No expenses recorded in this period.'}
            </p>
          )}
        </Panel>

        <Panel title="Collections by payment method" scope={data?.period_label}>
          {data && data.by_method.length > 0 ? (
            <div className="space-y-0">
              {data.by_method.map((slice) => (
                <div
                  key={slice.label}
                  className={`flex items-center justify-between py-2.5 px-2 border-b last:border-0 ${
                    isDarkMode ? 'border-gray-700/50' : 'border-gray-300'
                  }`}
                >
                  <div className="min-w-0 flex-1 pr-4">
                    <div className={`font-medium truncate ${isDarkMode ? 'text-slate-300' : 'text-slate-700'}`}>
                      {slice.label}
                    </div>
                    <div className={`text-xs ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}>
                      {slice.count.toLocaleString()} payments
                    </div>
                  </div>
                  <span className="text-base font-bold whitespace-nowrap">{formatCurrency(slice.total)}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className={isDarkMode ? 'text-slate-500' : 'text-slate-500'}>
              {loading ? 'Loading...' : 'No collections in this period.'}
            </p>
          )}
        </Panel>
      </div>

      {/* ── Branch comparison ───────────────────────────────── */}
      <Panel title="Branch performance" scope={`${data?.period_label ?? ''} · all branches regardless of filter`}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr
                className={`text-left uppercase tracking-widest text-[10px] ${
                  isDarkMode ? 'text-slate-400' : 'text-slate-500'
                }`}
              >
                <th className="px-2 py-2 font-bold">Branch</th>
                <th className="px-2 py-2 font-bold text-right">Income</th>
                <th className="px-2 py-2 font-bold text-right">Expenses</th>
                <th className="px-2 py-2 font-bold text-right">Net</th>
                <th className="px-2 py-2 font-bold text-right">Subscribers</th>
              </tr>
            </thead>
            <tbody>
              {(data?.by_branch ?? []).map((row) => (
                <tr key={row.id} className={`border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-300'}`}>
                  <td className="px-2 py-2.5 font-semibold">{row.label}</td>
                  <td className="px-2 py-2.5 text-right text-emerald-500 font-bold">
                    {formatCurrency(row.income)}
                  </td>
                  <td className="px-2 py-2.5 text-right text-red-500">{formatCurrency(row.expenses)}</td>
                  <td
                    className={`px-2 py-2.5 text-right font-bold ${
                      row.net >= 0 ? 'text-emerald-500' : 'text-red-500'
                    }`}
                  >
                    {formatCurrency(row.net)}
                  </td>
                  <td className="px-2 py-2.5 text-right">{row.subscribers.toLocaleString()}</td>
                </tr>
              ))}

              {!loading && (data?.by_branch.length ?? 0) === 0 && (
                <tr>
                  <td colSpan={5} className={`px-2 py-6 text-center ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}>
                    No branch data.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </Panel>

      {/* ── Plan mix ────────────────────────────────────────── */}
      {data && data.plans.length > 0 && (
        <Panel title="Active subscribers by plan" scope={data.branch_label}>
          <div className="h-[260px]">
            <Doughnut
              options={countDoughnutOptions as any}
              data={{
                labels: data.plans.map((plan) => plan.label),
                datasets: [
                  {
                    data: data.plans.map((plan) => plan.count),
                    backgroundColor: sliceColors,
                    borderWidth: 0,
                  },
                ],
              }}
            />
          </div>
        </Panel>
      )}

      <p className={`text-xs flex items-start gap-2 ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}>
        <Wallet size={14} className="mt-0.5 flex-shrink-0" />
        <span>
          Income counts payments marked paid. Expenses tagged for a longer reporting period (monthly, yearly)
          are excluded from shorter views, matching NetManager's own reports — so a day never carries a
          month's rent.
        </span>
      </p>
    </PageShell>
  );
};

export default Financials;
