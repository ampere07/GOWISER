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
  /**
   * Draws the value on each slice. On by default: a pie whose numbers are only
   * in the tooltip cannot be read on a wall display or in a printout, which is
   * where these charts are actually looked at.
   */
  showValues?: boolean;
}

/**
 * Writes each slice's value onto the slice.
 *
 * Hand-rolled rather than pulled from chartjs-plugin-datalabels: the whole need
 * is one `afterDatasetsDraw` hook, and a dependency for that is a dependency to
 * keep patched forever. Registered locally on the charts that ask for it rather
 * than globally, so the bar and line charts are untouched.
 *
 * Two rules keep it readable rather than a cloud of overlapping text:
 *
 *  - slices under 5% are skipped. Their labels would collide with their
 *    neighbours' and none of the three would be legible; the tooltip and the
 *    legend still carry them.
 *  - the text is drawn at the arc's midpoint with a contrasting halo, so it
 *    survives whichever palette colour it lands on.
 */
const sliceValuePlugin = (format: (value: number) => string) => ({
  id: 'sliceValues',
  afterDatasetsDraw(chart: any) {
    const { ctx } = chart;
    const meta = chart.getDatasetMeta(0);

    if (!meta || meta.hidden) return;

    const values: number[] = chart.data.datasets[0]?.data ?? [];
    const total = values.reduce((sum, value) => sum + Number(value || 0), 0);

    if (total <= 0) return;

    ctx.save();
    ctx.font = '600 11px system-ui, -apple-system, "Segoe UI", sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    meta.data.forEach((arc: any, index: number) => {
      const value = Number(values[index] || 0);

      if (value <= 0 || value / total < 0.05) return;

      const { x, y } = arc.getCenterPoint();
      const text = format(value);

      // Halo first, fill second: the palette runs from near-white to deep
      // purple and neither a light nor a dark label works on all of it.
      ctx.lineWidth = 3;
      ctx.strokeStyle = 'rgba(0,0,0,0.55)';
      ctx.strokeText(text, x, y);
      ctx.fillStyle = '#ffffff';
      ctx.fillText(text, x, y);
    });

    ctx.restore();
  },
});

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

/** The value formatter the slice labels use, matching the tooltip's. */
const sliceFormat = (unit: 'money' | 'count') =>
  unit === 'money' ? (value: number) => formatMoneyShort(value) : (value: number) => formatNumber(value);

/** Donut, for compositions where the total itself is not the point. */
export const DonutChart: React.FC<SliceChartProps> = ({
  labels,
  values,
  unit = 'money',
  height = 260,
  colors = SLICE_COLORS,
  showValues = true,
}) => {
  const theme = useChartTheme();

  return (
    <div style={{ height }} className="relative">
      <Doughnut
        options={sliceOptions(theme, unit) as any}
        // Passed per-instance rather than registered globally, so the bar and
        // line charts elsewhere are untouched by it.
        plugins={showValues ? [sliceValuePlugin(sliceFormat(unit))] : []}
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
  showValues = true,
}) => {
  const theme = useChartTheme();

  return (
    <div style={{ height }} className="relative">
      <Pie
        options={sliceOptions(theme, unit) as any}
        plugins={showValues ? [sliceValuePlugin(sliceFormat(unit))] : []}
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
