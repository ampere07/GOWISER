import React, { useState } from 'react';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js';
import { Line } from 'react-chartjs-2';
import { TrendingDown, TrendingUp } from 'lucide-react';
import PageShell from '../components/common/PageShell';
import Panel from '../components/common/Panel';
import MetricCard, { formatCurrency } from '../components/common/MetricCard';
import { monitorService } from '../services/monitorService';
import { useSourcedData } from '../hooks/useSourcedData';
import { useTheme } from '../hooks/useTheme';
import { usePalette } from '../hooks/usePalette';
import { Revenue as RevenueData, SourcedResponse } from '../types/monitor';

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
);

interface RevenueProps {
  refreshToken: number;
}

const RevenuePage: React.FC<RevenueProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const palette = usePalette();
  const [months] = useState(12);

  const { data, loading, error } = useSourcedData<SourcedResponse<RevenueData>>(
    (source) => monitorService.getRevenue(source, months),
    refreshToken
  );

  const revenue = data?.data;
  const series = revenue?.monthly || [];

  // The current month is partial, so comparing it to last month's total is
  // misleading. Compare the two most recent *complete* months instead.
  const complete = series.slice(0, -1);
  const current = series[series.length - 1];
  const lastComplete = complete[complete.length - 1];
  const priorComplete = complete[complete.length - 2];

  const change =
    lastComplete && priorComplete && priorComplete.total > 0
      ? ((lastComplete.total - priorComplete.total) / priorComplete.total) * 100
      : null;

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: isDarkMode ? '#1e293b' : '#ffffff',
        titleColor: isDarkMode ? '#f1f5f9' : '#0f172a',
        bodyColor: isDarkMode ? '#f1f5f9' : '#0f172a',
        borderColor: isDarkMode ? '#334155' : '#e2e8f0',
        borderWidth: 1,
        padding: 12,
        cornerRadius: 12,
        displayColors: false,
        callbacks: {
          label: (context: any) => formatCurrency(context.parsed.y),
        },
      },
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: isDarkMode ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)' },
        ticks: {
          color: isDarkMode ? 'rgba(255, 255, 255, 0.5)' : 'rgba(0, 0, 0, 0.5)',
          font: { size: 10 },
          callback: (value: any) => formatCurrency(Number(value)),
        },
      },
      x: {
        grid: { display: false },
        ticks: { color: isDarkMode ? 'rgba(255, 255, 255, 0.5)' : 'rgba(0, 0, 0, 0.5)', font: { size: 10 } },
      },
    },
  };

  const chartData = {
    labels: series.map((point) =>
      new Date(`${point.period}-01`).toLocaleDateString('en-US', { month: 'short', year: '2-digit' })
    ),
    datasets: [
      {
        data: series.map((point) => point.total),
        borderColor: palette.primary,
        backgroundColor: `${palette.primary}22`,
        fill: true,
        tension: 0.35,
        pointRadius: 3,
        pointBackgroundColor: palette.primary,
      },
    ],
  };

  const mtdTotal = revenue?.mtd_by_method.reduce((sum, slice) => sum + slice.total, 0) ?? 0;

  return (
    <PageShell
      title="Revenue"
      subtitle={data ? `${data.source_label} · collections over the last ${months} months` : 'Loading source...'}
      error={error}
    >
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 md:gap-6">
        <MetricCard
          title="This Month So Far"
          value={current?.total}
          currency
          caption={current ? `${current.transactions.toLocaleString()} payments` : undefined}
          loading={loading}
        />
        <MetricCard
          title="Last Full Month"
          value={lastComplete?.total}
          currency
          caption={lastComplete ? lastComplete.period : undefined}
          loading={loading}
        />
        <MetricCard
          title="Month-on-Month"
          value={change !== null ? `${change >= 0 ? '+' : ''}${change.toFixed(1)}%` : '—'}
          icon={change !== null && change < 0 ? <TrendingDown size={20} /> : <TrendingUp size={20} />}
          iconColor={change !== null && change < 0 ? 'text-red-500' : 'text-emerald-500'}
          caption="Last two complete months"
          loading={loading}
        />
      </div>

      <Panel title="Collections trend" scope={`${months} months, current month partial`}>
        <div className="h-[320px] relative">
          <Line options={chartOptions} data={chartData} />
        </div>
      </Panel>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Panel title="By payment method" scope="Month to date">
          {revenue && revenue.mtd_by_method.length > 0 ? (
            <div className="space-y-0">
              {revenue.mtd_by_method.map((slice) => (
                <div
                  key={slice.label}
                  className={`flex items-center justify-between py-3 px-2 border-b last:border-0 ${
                    isDarkMode ? 'border-gray-700/50' : 'border-gray-300'
                  }`}
                >
                  <div className="min-w-0 flex-1 pr-4">
                    <div className={`font-medium truncate ${isDarkMode ? 'text-slate-300' : 'text-slate-700'}`}>
                      {slice.label}
                    </div>
                    <div className={`text-xs ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}>
                      {slice.count?.toLocaleString()} payments
                      {mtdTotal > 0 && ` · ${Math.round((slice.total / mtdTotal) * 100)}%`}
                    </div>
                  </div>
                  <span className="text-base font-bold whitespace-nowrap">{formatCurrency(slice.total)}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className={isDarkMode ? 'text-slate-500' : 'text-slate-500'}>
              {loading ? 'Loading...' : 'No collections recorded this month.'}
            </p>
          )}
        </Panel>

        <Panel title="By charge type" scope="Month to date">
          {revenue && revenue.mtd_by_type.length > 0 ? (
            <div className="space-y-0">
              {revenue.mtd_by_type.map((slice) => (
                <div
                  key={slice.label}
                  className={`flex items-center justify-between py-3 px-2 border-b last:border-0 ${
                    isDarkMode ? 'border-gray-700/50' : 'border-gray-300'
                  }`}
                >
                  <span className={`font-medium capitalize ${isDarkMode ? 'text-slate-400' : 'text-slate-600'}`}>
                    {slice.label}
                  </span>
                  <span className="text-base font-bold whitespace-nowrap">{formatCurrency(slice.total)}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className={isDarkMode ? 'text-slate-500' : 'text-slate-500'}>
              {loading ? 'Loading...' : 'No collections recorded this month.'}
            </p>
          )}
        </Panel>
      </div>

      <p className={`text-xs ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}>
        Figures count payments with a recorded payment date, excluding cancelled, voided and still-pending
        rows. They will not match a finance report that recognises revenue on a different basis.
      </p>
    </PageShell>
  );
};

export default RevenuePage;
