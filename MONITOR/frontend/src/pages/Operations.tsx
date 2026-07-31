import React from 'react';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js';
import { Bar } from 'react-chartjs-2';
import { ClipboardList, Wrench, Headphones } from 'lucide-react';
import PageShell from '../components/common/PageShell';
import Panel from '../components/common/Panel';
import MetricCard from '../components/common/MetricCard';
import StatusList from '../components/common/StatusList';
import { monitorService } from '../services/monitorService';
import { useSourcedData } from '../hooks/useSourcedData';
import { useTheme } from '../hooks/useTheme';
import { usePalette } from '../hooks/usePalette';
import { LabelledCount, Operations as OperationsData, SourcedResponse } from '../types/monitor';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

interface OperationsProps {
  refreshToken: number;
}

const todayScope = (): string => {
  const today = new Date();
  const pad = (n: number) => n.toString().padStart(2, '0');
  const stamp = `${pad(today.getMonth() + 1)}/${pad(today.getDate())}/${today.getFullYear()}`;
  return `${stamp} 00:00:00 - ${stamp} 23:59:59`;
};

const monthScope = (): string =>
  new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

const Operations: React.FC<OperationsProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const palette = usePalette();
  const { data, loading, error } = useSourcedData<SourcedResponse<OperationsData>>(
    (source) => monitorService.getOperations(source),
    refreshToken
  );

  const operations = data?.data;

  const chartOptions = {
    indexAxis: 'x' as const,
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
      },
    },
    scales: {
      y: {
        beginAtZero: true,
        grace: '20%',
        grid: { color: isDarkMode ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)' },
        ticks: { color: isDarkMode ? 'rgba(255, 255, 255, 0.5)' : 'rgba(0, 0, 0, 0.5)', font: { size: 10 } },
      },
      x: {
        grid: { display: false },
        ticks: {
          color: isDarkMode ? 'rgba(255, 255, 255, 0.5)' : 'rgba(0, 0, 0, 0.5)',
          font: { size: 10, weight: 600 },
          maxRotation: 45,
          minRotation: 45,
        },
      },
    },
  };

  const chartData = (rows: LabelledCount[] | undefined, color: string) => ({
    labels: rows?.map((row) => row.label) || [],
    datasets: [
      {
        data: rows?.map((row) => row.count) || [],
        backgroundColor: color,
        borderRadius: 8,
        barThickness: 24,
      },
    ],
  });

  return (
    <PageShell
      title="Operations"
      subtitle={data ? `${data.source_label} · field and support activity` : 'Loading source...'}
      error={error}
    >
      {/* Backlog is the number a manager can act on today. */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 md:gap-6">
        <MetricCard
          title="Applications Pending"
          value={operations?.backlog.applications_pending}
          icon={<ClipboardList size={20} />}
          iconColor="text-orange-500"
          caption="All time, not yet scheduled"
          loading={loading}
        />
        <MetricCard
          title="Job Orders In Progress"
          value={operations?.backlog.job_orders_in_progress}
          icon={<Wrench size={20} />}
          iconColor="text-indigo-500"
          caption="Open onsite work"
          loading={loading}
        />
        <MetricCard
          title="Support Tickets Open"
          value={operations?.backlog.service_orders_open}
          icon={<Headphones size={20} />}
          iconColor="text-red-500"
          caption="In progress or awaiting a visit"
          loading={loading}
        />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
        <Panel title="Support Status Today" scope={todayScope()}>
          <StatusList items={operations?.support_status_today} loading={loading} />
        </Panel>

        <Panel title="For Visit Today" scope={todayScope()}>
          <StatusList items={operations?.visit_status_today} loading={loading} />
        </Panel>

        <Panel title="Job Order Onsite Status" scope={todayScope()}>
          <StatusList items={operations?.job_order_status_today} loading={loading} />
        </Panel>

        <Panel title="Application Status" scope={todayScope()}>
          <StatusList items={operations?.application_status_today} loading={loading} />
        </Panel>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Panel title="Support Concerns" scope={monthScope()}>
          <div className="h-[280px] relative">
            <Bar options={chartOptions} data={chartData(operations?.monthly_support_concerns, palette.primary)} />
          </div>
        </Panel>

        <Panel title="Repair Categories" scope={monthScope()}>
          <div className="h-[280px] relative">
            <Bar
              options={chartOptions}
              data={chartData(operations?.monthly_repair_categories, palette.secondary)}
            />
          </div>
        </Panel>
      </div>
    </PageShell>
  );
};

export default Operations;
