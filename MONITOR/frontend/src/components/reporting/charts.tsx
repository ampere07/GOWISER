import React, { useMemo } from 'react';
import {
  ArcElement,
  BarController,
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  DoughnutController,
  Filler,
  Legend,
  LinearScale,
  LineController,
  LineElement,
  PieController,
  PointElement,
  Tooltip,
} from 'chart.js';
import { Bar, Doughnut, Pie } from 'react-chartjs-2';
import { useTheme } from '../../hooks/useTheme';
import { formatMoney, formatMoneyShort, formatNumber } from '../../utils/format';
import { TrendPoint, WorkTimelinePoint } from '../../types/reporting';

/**
 * Chart.js v4 is tree-shaken: nothing is available until it is registered, and
 * that includes the *controllers*, not only the elements and scales.
 *
 * Registering elements alone is the trap. A bar chart still builds, so the
 * omission looks harmless — until a dataset carries `type: 'line'` (TrendChart
 * draws net as a line over income/expense bars), at which point Chart.js throws
 * "line is not a registered controller" from inside its resize handler and the
 * whole page unmounts to a blank screen rather than losing one panel.
 *
 * All four controllers this module actually draws with are listed, so adding a
 * chart type here cannot reintroduce the same failure quietly.
 */
ChartJS.register(
  BarController,
  LineController,
  DoughnutController,
  PieController,
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
      // The label's first row, which has to out-weigh the muted second one.
      strong: isDarkMode ? '#e2e8f0' : '#1e293b',
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

/** Applications blue, job orders green, service orders amber. */
export const STREAM_COLORS = {
  applications: '#0d6efd',
  job_orders: '#20c997',
  service_orders: '#fd7e14',
} as const;

interface WorkStreamChartProps {
  points: WorkTimelinePoint[];
  height?: number;
  /**
   * Bars for the pipeline panel, lines everywhere else.
   *
   * Grouped bars read better beside a table of the same numbers: the eye moves
   * between a bar and its row without having to interpolate a point off a line.
   * Lines remain the default for the standalone chart, where the question is the
   * shape of the trend rather than the value on a given day.
   */
  variant?: 'line' | 'bar';
}

/**
 * Applications, job orders and service orders day by day on one axis.
 *
 * Three lines rather than stacked bars. The question this answers is whether one
 * stream is diverging from the others — installations climbing while repairs
 * stay flat — and stacking hides exactly that by making every series' position
 * depend on the ones below it. Lines also survive an empty day, which the
 * backend emits as a real zero rather than a gap (see workTimeline), so a quiet
 * week reads as a trough instead of two busy days drawn side by side.
 *
 * Counts, not money, so the axis starts at zero: unlike net income these cannot
 * go negative, and a floating baseline would exaggerate a two-job difference.
 */
export const WorkStreamChart: React.FC<WorkStreamChartProps> = ({
  points,
  height = 300,
  variant = 'line',
}) => {
  const theme = useChartTheme();

  const data = useMemo(
    () => ({
      labels: points.map((point) => point.label),
      datasets: (
        [
          ['applications', 'Applied'],
          ['job_orders', 'Installed'],
          ['service_orders', 'Repaired'],
        ] as const
      ).map(([key, label]) => ({
        label,
        data: points.map((point) => point[key]),
        ...(variant === 'bar'
          ? {
              type: 'bar' as const,
              backgroundColor: STREAM_COLORS[key],
              borderColor: STREAM_COLORS[key],
              borderWidth: 0,
              borderRadius: 3,
              // Grouped, never stacked: stacking makes each series' position
              // depend on the ones below it, which hides the divergence the
              // chart exists to show.
              stack: undefined,
            }
          : {
              type: 'line' as const,
              borderColor: STREAM_COLORS[key],
              backgroundColor: `${STREAM_COLORS[key]}22`,
              borderWidth: 2,
              // Points are hidden on a long range and shown on a short one:
              // thirty markers is a readable series, three hundred is a solid
              // band.
              pointRadius: points.length > 45 ? 0 : 3,
              pointHoverRadius: 4,
              pointBackgroundColor: STREAM_COLORS[key],
              fill: false,
              tension: 0.25,
            }),
      })),
    }),
    [points, variant]
  );

  const options = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index' as const, intersect: false },
      plugins: {
        legend: {
          position: 'top' as const,
          labels: {
            color: theme.tick,
            boxWidth: 12,
            boxHeight: 12,
            padding: 16,
            font: { size: 11 },
          },
        },
        tooltip: {
          ...theme.tooltip,
          callbacks: {
            label: (context: any) => ` ${context.dataset.label}: ${formatNumber(context.raw)}`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: theme.grid },
          border: { display: false },
          ticks: {
            color: theme.tick,
            font: { size: 10 },
            // Counts are whole. Without this a range whose peak is 3 draws
            // gridlines at 0.5, 1.5, 2.5 and invites reading half a job order.
            precision: 0,
          },
        },
        x: {
          grid: { display: false },
          border: { color: theme.grid },
          ticks: {
            color: theme.tick,
            font: { size: 10 },
            maxRotation: 45,
            minRotation: 0,
            autoSkip: true,
            maxTicksLimit: 16,
          },
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
   * Draws each slice's name and share outside the chart on a leader line.
   *
   * On by default: a chart whose numbers live only in a tooltip cannot be read
   * on a wall display or in a printout, which is where these are actually
   * looked at, and a legend forces the reader to match colours back and forth.
   *
   * The legend is shown instead when this is off, or when the panel is too
   * narrow to give outside labels room.
   */
  showValues?: boolean;
  /**
   * Suppresses the fallback legend, for a chart that sits beside a table
   * carrying the same rows.
   *
   * The legend is a fallback for a chart too narrow to label its own slices —
   * which is every phone. On a panel whose table stacks underneath the chart at
   * that same width, the fallback lands directly above a table listing the same
   * names, counts and shares, so the reader scrolls past the figures twice
   * before reaching anything new. The chart keeps its colours and its tooltip;
   * the table beside it is the legend.
   *
   * Off by default: a chart with no table beside it still needs the fallback,
   * and losing it silently would leave a phone reader with an unlabelled pie.
   */
  legendFallback?: boolean;
}

/**
 * Draws each slice's label outside the chart, joined to its slice by a leader
 * line: the category name on the first row, its share on a lighter second row.
 *
 * Hand-rolled rather than pulled from chartjs-plugin-datalabels: the whole need
 * is one `afterDatasetsDraw` hook plus the collision pass below, and a
 * dependency for that is a dependency to keep patched forever. Registered
 * per-instance rather than globally, so the bar and line charts are untouched.
 *
 * Labels sit outside rather than on the slices because a slice is often too
 * narrow to hold its own name, and a name that only appears in a legend forces
 * the reader to match colours back and forth. Three rules keep the result
 * legible rather than a cloud of overlapping text:
 *
 *  - slices under 2% are skipped entirely. There is no honest way to point at
 *    a two-degree wedge, and the tooltip still carries it.
 *  - labels are collected per side and pushed apart vertically until none
 *    overlaps its neighbour, then the leader line is bent to follow.
 *  - the whole thing is skipped when the plot area is too small to give the
 *    labels room, and the legend is drawn instead.
 */
const LABEL_LINE_HEIGHT = 13;
const LABEL_GUTTER = 8;

/**
 * Minimum plot width before outside labels stop fitting and the legend returns.
 *
 * Measured against the panel, not the viewport: these charts sit in half-width
 * grid cells that are narrow even on a desktop. Below this the two label columns
 * and the pie between them cannot all fit, and the legend is the honest fallback.
 */
export const SLICE_LABEL_MIN_WIDTH = 320;

const sliceLabelPlugin = (format: (value: number) => string, muted: string, strong: string) => ({
  id: 'sliceLabels',
  afterDatasetsDraw(chart: any) {
    const { ctx, chartArea } = chart;
    const meta = chart.getDatasetMeta(0);

    if (!meta || meta.hidden || !chartArea) return;
    if (chartArea.right - chartArea.left < SLICE_LABEL_MIN_WIDTH) return;

    const values: number[] = chart.data.datasets[0]?.data ?? [];
    const labels: string[] = chart.data.labels ?? [];
    const total = values.reduce((sum, value) => sum + Number(value || 0), 0);

    if (total <= 0) return;

    const centreX = (chartArea.left + chartArea.right) / 2;
    const centreY = (chartArea.top + chartArea.bottom) / 2;

    // Collected per side first: the anti-overlap pass has to consider the whole
    // column at once, which cannot be done while drawing arc by arc.
    const sides: { left: any[]; right: any[] } = { left: [], right: [] };

    meta.data.forEach((arc: any, index: number) => {
      const value = Number(values[index] || 0);
      const share = value / total;

      if (value <= 0 || share < 0.02) return;

      const angle = (arc.startAngle + arc.endAngle) / 2;
      const radius = arc.outerRadius ?? 0;

      const anchorX = centreX + Math.cos(angle) * radius;
      const anchorY = centreY + Math.sin(angle) * radius;
      const right = Math.cos(angle) >= 0;

      sides[right ? 'right' : 'left'].push({
        anchorX,
        anchorY,
        // Elbow sits a little outside the arc; the horizontal run then carries
        // the line to the text.
        elbowX: centreX + Math.cos(angle) * (radius + 16),
        y: anchorY,
        right,
        name: String(labels[index] ?? ''),
        detail: `${format(value)} · ${(share * 100).toFixed(1)}%`,
      });
    });

    /**
     * Pushes labels apart so no two overlap, then pulls the column back inside
     * the canvas if the shuffle drove it off the top or bottom.
     */
    const spread = (items: any[]) => {
      items.sort((a, b) => a.y - b.y);

      const minGap = LABEL_LINE_HEIGHT * 2 + 4;

      for (let i = 1; i < items.length; i += 1) {
        const gap = items[i].y - items[i - 1].y;

        if (gap < minGap) {
          items[i].y = items[i - 1].y + minGap;
        }
      }

      const overflow = items.length
        ? items[items.length - 1].y + LABEL_LINE_HEIGHT - chart.height
        : 0;

      if (overflow > 0) {
        for (let i = items.length - 1; i >= 0; i -= 1) {
          items[i].y -= overflow;

          if (i > 0 && items[i].y - items[i - 1].y >= minGap) break;
        }
      }

      items.forEach((item) => {
        item.y = Math.max(LABEL_LINE_HEIGHT, Math.min(chart.height - LABEL_LINE_HEIGHT, item.y));
      });
    };

    spread(sides.left);
    spread(sides.right);

    ctx.save();
    ctx.font = '500 11px system-ui, -apple-system, "Segoe UI", sans-serif';

    [...sides.left, ...sides.right].forEach((item) => {
      const endX = item.right ? chartArea.right - 4 : chartArea.left + 4;
      const textX = item.right ? endX - LABEL_GUTTER : endX + LABEL_GUTTER;

      ctx.strokeStyle = muted;
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(item.anchorX, item.anchorY);
      ctx.lineTo(item.elbowX, item.y);
      ctx.lineTo(textX, item.y);
      ctx.stroke();

      ctx.textAlign = item.right ? 'right' : 'left';
      ctx.textBaseline = 'middle';

      // Name on top in the foreground colour, share underneath in the muted
      // one — the same hierarchy the tooltip uses, so the two agree.
      ctx.fillStyle = strong;
      ctx.fillText(item.name, textX, item.y - LABEL_LINE_HEIGHT / 2);

      ctx.fillStyle = muted;
      ctx.fillText(item.detail, textX, item.y + LABEL_LINE_HEIGHT / 2);
    });

    ctx.restore();
  },
});

const sliceOptions = (
  theme: ReturnType<typeof useChartTheme>,
  unit: 'money' | 'count',
  labelled: boolean
) => ({
  responsive: true,
  maintainAspectRatio: false,
  // Room down each side for the outside labels and their leader lines. Without
  // it Chart.js fills the box with the pie and the labels are clipped.
  layout: labelled ? { padding: { left: 92, right: 92, top: 12, bottom: 12 } } : undefined,
  plugins: {
    legend: {
      // Never Chart.js's own. When there is room, each slice is named beside
      // itself and a legend is redundant; when there is not, SliceLegend below
      // renders one in HTML instead. Chart.js draws its legend inside the canvas
      // at a fixed font size, which on a phone came out as unreadable 10px text
      // carrying only the category name — no value, no share — and silently
      // dropped entries once they ran out of canvas.
      display: false,
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

/**
 * Whether outside labels have room.
 *
 * Measured from the rendered element rather than assumed from the viewport:
 * these charts sit in grid cells that are narrow at desktop widths too, and a
 * media query would get it wrong in exactly those cases.
 */
const useHasLabelRoom = (): [React.RefObject<HTMLDivElement | null>, boolean] => {
  const ref = React.useRef<HTMLDivElement>(null);
  const [roomy, setRoomy] = React.useState(true);

  React.useEffect(() => {
    const element = ref.current;

    if (!element || typeof ResizeObserver === 'undefined') return;

    const observer = new ResizeObserver(([entry]) =>
      setRoomy(entry.contentRect.width >= SLICE_LABEL_MIN_WIDTH)
    );

    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  return [ref, roomy];
};

/**
 * The legend shown when the chart is too narrow for outside labels — which is
 * every phone, and plenty of half-width desktop panels.
 *
 * HTML rather than Chart.js's canvas legend, because the canvas one was the bug:
 * it renders at a fixed pixel size that does not scale with the device, it wraps
 * by dropping entries rather than reflowing them, and its label is the category
 * name alone. A reader on a phone saw "PLAN-C" and no number at all, while the
 * desktop reader saw "PLAN-C · 1,969 · 54.9%" — the same chart making two
 * different claims about how much it knew.
 *
 * Laid out as a two-column grid that collapses to one on the narrowest screens,
 * with the figure right-aligned so the numbers form a readable column instead of
 * ragging off the end of names of different lengths.
 */
const SliceLegend: React.FC<{
  labels: string[];
  values: number[];
  colors: string[];
  unit: 'money' | 'count';
}> = ({ labels, values, colors, unit }) => {
  const isDarkMode = useTheme();
  const format = sliceFormat(unit);
  const total = values.reduce((sum, value) => sum + Number(value || 0), 0);

  if (total <= 0) return null;

  return (
    <ul className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 mt-3 list-none">
      {labels.map((label, index) => {
        const value = Number(values[index] || 0);
        const share = total > 0 ? (value / total) * 100 : 0;

        return (
          <li key={`${label}-${index}`} className="flex items-center gap-2 min-w-0 text-xs">
            <span
              className="inline-block w-2.5 h-2.5 rounded-sm flex-shrink-0"
              style={{ backgroundColor: colors[index % colors.length] }}
              aria-hidden
            />
            <span className={`truncate ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
              {label}
            </span>
            <span
              className={`ml-auto flex-shrink-0 tabular-nums font-medium ${
                isDarkMode ? 'text-gray-100' : 'text-gray-900'
              }`}
            >
              {format(value)}
            </span>
            <span
              className={`flex-shrink-0 tabular-nums w-12 text-right ${
                isDarkMode ? 'text-gray-500' : 'text-gray-500'
              }`}
            >
              {share.toFixed(1)}%
            </span>
          </li>
        );
      })}
    </ul>
  );
};

/** Donut, for compositions where the total itself is not the point. */
export const DonutChart: React.FC<SliceChartProps> = ({
  labels,
  values,
  unit = 'money',
  height = 260,
  colors = SLICE_COLORS,
  showValues = true,
  legendFallback = true,
}) => {
  const theme = useChartTheme();
  const [ref, roomy] = useHasLabelRoom();

  const labelled = showValues && roomy;

  return (
    // The ref measures the available width, so it must sit on the element that
    // actually spans the panel — not on the fixed-height canvas box below it.
    <div ref={ref}>
      <div style={{ height }} className="relative">
        <Doughnut
          options={sliceOptions(theme, unit, labelled) as any}
          // Passed per-instance rather than registered globally, so the bar and
          // line charts elsewhere are untouched by it.
          plugins={labelled ? [sliceLabelPlugin(sliceFormat(unit), theme.tick, theme.strong)] : []}
          data={{
            labels,
            datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }],
          }}
        />
      </div>

      {!labelled && legendFallback && (
        <SliceLegend labels={labels} values={values} colors={colors} unit={unit} />
      )}
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
  legendFallback = true,
}) => {
  const theme = useChartTheme();
  const [ref, roomy] = useHasLabelRoom();

  const labelled = showValues && roomy;

  return (
    <div ref={ref}>
      <div style={{ height }} className="relative">
        <Pie
          options={sliceOptions(theme, unit, labelled) as any}
          plugins={labelled ? [sliceLabelPlugin(sliceFormat(unit), theme.tick, theme.strong)] : []}
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

      {!labelled && legendFallback && (
        <SliceLegend labels={labels} values={values} colors={colors} unit={unit} />
      )}
    </div>
  );
};
