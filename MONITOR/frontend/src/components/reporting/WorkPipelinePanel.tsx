import React, { useMemo } from 'react';
import { BarChart3 } from 'lucide-react';
import { WorkTimelinePoint } from '../../types/reporting';
import { LinkedRangeState } from '../../hooks/useLinkedRange';
import { WidgetRangeState } from '../../hooks/useWidgetRange';
import { useTheme } from '../../hooks/useTheme';
import { formatNumber } from '../../utils/format';
import Card, { CardHeader, CardBody } from './Card';
import { PanelState } from './primitives';
import WidgetRange from './WidgetRange';
import { STREAM_COLORS, WorkStreamChart } from './charts';

/**
 * The Apply → Install → Repair pipeline: bars on the left, the same numbers on
 * the right.
 *
 * Both halves read the one array. A chart alone cannot be quoted — nobody reads
 * "about forty" off a bar and puts it in a report — and a table alone hides the
 * shape. Side by side, the chart carries the trend and the table carries the
 * figures, and because they are rendered from the same points they cannot drift
 * apart.
 *
 * The table is scrolled rather than truncated. A year-long range is 365 rows,
 * and a "top 20 days" would answer a question nobody asked; the totals row is
 * pinned to the bottom so the summary is reachable without scrolling to it.
 */

interface WorkPipelinePanelProps {
  points: WorkTimelinePoint[];
  rangeLabel?: string;
  range: WidgetRangeState | LinkedRangeState;
  loading: boolean;
  error: string | null;
}

const STREAMS = [
  { key: 'applications', label: 'Applied', color: STREAM_COLORS.applications },
  { key: 'job_orders', label: 'Installed', color: STREAM_COLORS.job_orders },
  { key: 'service_orders', label: 'Repaired', color: STREAM_COLORS.service_orders },
] as const;

const WorkPipelinePanel: React.FC<WorkPipelinePanelProps> = ({
  points,
  rangeLabel,
  range,
  loading,
  error,
}) => {
  const isDarkMode = useTheme();

  const totals = useMemo(
    () =>
      points.reduce(
        (acc, point) => ({
          applications: acc.applications + (point.applications ?? 0),
          job_orders: acc.job_orders + (point.job_orders ?? 0),
          service_orders: acc.service_orders + (point.service_orders ?? 0),
        }),
        { applications: 0, job_orders: 0, service_orders: 0 }
      ),
    [points]
  );

  // Newest first. The table is read for "what happened lately", and a range that
  // opens on January of a year-long window buries that under 300 rows.
  const rows = useMemo(() => [...points].reverse(), [points]);

  return (
    <Card flush>
      <CardHeader
        title="Apply · Install · Repair"
        subtitle={rangeLabel ? `Day by day · ${rangeLabel}` : 'Day by day'}
        icon={<BarChart3 size={16} />}
        actions={<WidgetRange state={range} />}
      />
      <CardBody>
        <PanelState
          loading={loading && points.length === 0}
          error={error}
          empty={points.length === 0}
          emptyMessage="No applications, installations or repairs in this range."
          height={320}
        >
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div>
              <WorkStreamChart points={points} variant="bar" height={320} />
            </div>

            <div className="min-w-0">
              {/* Totals first: the single most-read figure on the panel should
                  not be at the far end of a scroll. */}
              <div className="grid grid-cols-3 gap-2 mb-3">
                {STREAMS.map((stream) => (
                  <div
                    key={stream.key}
                    className={`rounded-lg px-2.5 py-2 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
                  >
                    <p className="flex items-center gap-1.5 text-[11px]">
                      <span
                        className="inline-block w-2 h-2 rounded-sm flex-shrink-0"
                        style={{ backgroundColor: stream.color }}
                      />
                      <span className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                        {stream.label}
                      </span>
                    </p>
                    <p
                      className={`text-lg font-bold tabular-nums ${
                        isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}
                    >
                      {formatNumber(totals[stream.key])}
                    </p>
                  </div>
                ))}
              </div>

              <div className="overflow-auto max-h-[248px] -mx-1 px-1">
                <table className="w-full text-sm">
                  <thead className="sticky top-0 z-10">
                    <tr className={isDarkMode ? 'bg-gray-900' : 'bg-white'}>
                      <th
                        className={`text-left py-2 pr-2 text-[11px] font-semibold uppercase tracking-wide ${
                          isDarkMode ? 'text-gray-400' : 'text-gray-500'
                        }`}
                      >
                        Day
                      </th>
                      {STREAMS.map((stream) => (
                        <th
                          key={stream.key}
                          className={`text-right py-2 px-1 text-[11px] font-semibold uppercase tracking-wide ${
                            isDarkMode ? 'text-gray-400' : 'text-gray-500'
                          }`}
                        >
                          {stream.label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((point) => (
                      <tr
                        key={point.period}
                        className={`border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-100'}`}
                      >
                        <td
                          className={`py-1.5 pr-2 whitespace-nowrap ${
                            isDarkMode ? 'text-gray-300' : 'text-gray-700'
                          }`}
                        >
                          {point.label}
                        </td>
                        {STREAMS.map((stream) => (
                          <td
                            key={stream.key}
                            className={`py-1.5 px-1 text-right tabular-nums ${
                              (point[stream.key] ?? 0) === 0
                                ? isDarkMode
                                  ? 'text-gray-600'
                                  : 'text-gray-300'
                                : isDarkMode
                                ? 'text-gray-100'
                                : 'text-gray-900'
                            }`}
                          >
                            {formatNumber(point[stream.key])}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </PanelState>
      </CardBody>
    </Card>
  );
};

export default WorkPipelinePanel;
