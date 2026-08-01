import React, { useMemo } from 'react';
import {
  ArcElement,
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js';
import { Bar, Doughnut, Pie } from 'react-chartjs-2';
import { useTheme } from '../../hooks/useTheme';
import { formatMoney, formatMoneyShort, formatNumber } from '../../utils/format';
import { TrendPoint } from '../../types/reporting';

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

/** Income green, expenses red, net blue — as the source dashboard has them. */
export const INCOME_COLOR = '#198754';
export const EXPENSE_COLOR = '#dc3545';
export const NET_COLOR = '#0d6efd';

/**
 * Categorical palette for the plan / router / method breakdowns.
 *
 * Fixed rather than palette-derived: these slices are compared against the
 * printed reports and the source dashboard, and a colour that shifts with the
 * brand palette would make "the blue one" mean different things in each.
 */
export const SLICE_COLORS = [
  '#0d6efd',
  '#20c997',
  '#6f42c1',
  '#fd7e14',
  '#d63384',
  '#0dcaf0',
  '#ffc107',
  '#198754',
  '#6610f2',
  '#e83e8c',
  '#adb5bd',
  '#495057',
];

/** Axis, grid and tooltip colours for the current theme. */
const useChartTheme = () => {
  const isDarkMode = useTheme();

  return useMemo(
    () => ({
      isDarkMode,
      grid: isDarkMode ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
      tick: isDarkMode ? 'rgba(226,232,240,0.65)' : 'rgba(30,41,59,0.65)',
      tooltip: {
        backgroundColor: isDarkMode ? '#1e293b' : '#ffffff',
        titleColor: isDarkMode ? '#f1f5f9' : '#0f172a',
        bodyColor: isDarkMode ? '#f1f5f9' : '#0f172a',
        borderColor: isDarkMode ? '#334155' : '#e2e8f0',
        borderWidth: 1,
        padding: 10,
        cornerRadius: 8,
        displayColors: true,
      },
    }),
    [isDarkMode]
  );
};

interface TrendChartProps {
  points: TrendPoint[];
  height?: number;
}

/**
 * Income and expenses as bars with net as a line over them.
 *
 * Net is a line, not a third bar, because it is a different kind of quantity —
 * a difference rather than a total — and three bars per period invites reading
 * it as another category of money coming in.
 */
export const TrendChart: React.FC<TrendChartProps> = ({ points, height = 340 }) => {
  const theme = useChartTheme();

  const data = useMemo(
    () => ({
      labels: points.map((point) => point.label),
      datasets: [
        {
          label: 'Net',
          data: points.map((point) => point.net),
          type: 'line' as const,
          borderColor: NET_COLOR,
          backgroundColor: `${NET_COLOR}1a`,
          borderWidth: 2,
          pointRadius: 3,
          pointBackgroundColor: NET_COLOR,
          fill: false,
          tension: 0.3,
          order: 0,
        },
        {
          label: 'Income',
          data: points.map((point) => point.income),
          backgroundColor: `${INCOME_COLOR}cc`,
          borderColor: INCOME_COLOR,
          borderWidth: 1,
          borderRadius: 3,
          order: 1,
        },
        {
          label: 'Expenses',
          data: points.map((point) => point.expenses),
          backgroundColor: `${EXPENSE_COLOR}cc`,
          borderColor: EXPENSE_COLOR,
          borderWidth: 1,
          borderRadius: 3,
          order: 2,
        },
      ],
    }),
    [points]
  );

  const options = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      // Hovering anywhere in a period shows all three values for it, which is
      // the comparison the chart exists to make.
      interaction: { mode: 'index' as const, intersect: false },
      plugins: {
        legend: {
          position: 'top' as const,
          align: 'center' as const,
          labels: {
            color: theme.tick,
            boxWidth: 12,
            boxHeight: 12,
            padding: 16,
            usePointStyle: false,
            font: { size: 11 },
          },
        },
        tooltip: {
          ...theme.tooltip,
          callbacks: {
            label: (context: any) => ` ${context.dataset.label}: ${formatMoney(context.raw)}`,
          },
        },
      },
      scales: {
        y: {
          // Not beginAtZero: a loss-making period has a negative net, and
          // clamping the axis at zero would hide the line entirely.
          grid: { color: theme.grid },
          border: { display: false },
          ticks: {
            color: theme.tick,
            font: { size: 10 },
            callback: (value: any) => formatMoneyShort(Number(value)),
          },
        },
        x: {
          grid: { display: false },
          border: { color: theme.grid },
          ticks: { color: theme.tick, font: { size: 10 }, maxRotation: 45, minRotation: 0 },
        },
      },
    }),
    [theme]
  );

  return (
    <div style={{ height }} className="relative">
      <Bar options={options as any} data={data as any} />
    </div>
  );
};

interface SliceChartProps {
  labels: string[];
  values: number[];
  /** Formats the tooltip value — money for revenue, a count for subscribers. */
  unit?: 'money' | 'count';
  height?: number;
  colors?: string[];
}

const sliceOptions = (theme: ReturnType<typeof useChartTheme>, unit: 'money' | 'count') => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom' as const,
      labels: {
        color: theme.tick,
        boxWidth: 10,
        boxHeight: 10,
        padding: 10,
        font: { size: 10 },
      },
    },
    tooltip: {
      ...theme.tooltip,
      callbacks: {
        label: (context: any) => {
          const value = Number(context.raw);
          // Total comes from the dataset, so the share shown matches this chart
          // even when the caller passed a filtered subset.
          const total = (context.dataset.data as number[]).reduce(
            (sum: number, item: number) => sum + Number(item),
            0
          );
          const share = total > 0 ? ` (${((value / total) * 100).toFixed(1)}%)` : '';

          return ` ${context.label}: ${unit === 'money' ? formatMoney(value) : formatNumber(value)}${share}`;
        },
      },
    },
  },
});

/** Donut, for compositions where the total itself is not the point. */
export const DonutChart: React.FC<SliceChartProps> = ({
  labels,
  values,
  unit = 'money',
  height = 260,
  colors = SLICE_COLORS,
}) => {
  const theme = useChartTheme();

  return (
    <div style={{ height }} className="relative">
      <Doughnut
        options={sliceOptions(theme, unit) as any}
        data={{
          labels,
          datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }],
        }}
      />
    </div>
  );
};

/** Solid pie — used by Router Reports, matching the reference. */
export const PieChart: React.FC<SliceChartProps> = ({
  labels,
  values,
  unit = 'money',
  height = 260,
  colors = SLICE_COLORS,
}) => {
  const theme = useChartTheme();

  return (
    <div style={{ height }} className="relative">
      <Pie
        options={sliceOptions(theme, unit) as any}
        data={{
          labels,
          datasets: [
            {
              data: values,
              backgroundColor: colors,
              borderWidth: 1,
              borderColor: theme.isDarkMode ? '#0f172a' : '#ffffff',
            },
          ],
        }}
      />
    </div>
  );
};
