import React, { useCallback, useEffect, useState } from 'react';
import { Link2Off } from 'lucide-react';
import { WidgetRangeState } from '../../hooks/useWidgetRange';
import { LinkedRangeState } from '../../hooks/useLinkedRange';
import { reportingService } from '../../services/reportingService';
import { WorkStreams } from '../../types/reporting';
import { useTheme } from '../../hooks/useTheme';
import { formatNumber } from '../../utils/format';
import Card, { CardHeader, CardBody } from './Card';
import { PanelState } from './primitives';
import WidgetRange from './WidgetRange';

/**
 * One work stream, on a range it does not own.
 *
 * The range is passed in rather than created here, because these widgets follow
 * the page-level control until somebody overrides one of them — see
 * useLinkedRange. Owning it locally would have made that impossible: the global
 * bar could never have reached a widget that constructed its own state.
 *
 * The cost of independent ranges is one request each, which is smaller than it
 * looks: reportingService keys its cache on the range, so the common case of all
 * three still following the page collapses into one HTTP call, and the server
 * caches per parameter bucket on top of that. Only a widget somebody has
 * actually moved pays for itself.
 */
export const useWorkStream = (range: WidgetRangeState, refreshToken = 0) => {
  const [data, setData] = useState<WorkStreams | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const { from, to } = range.range;

  const load = useCallback(() => {
    let cancelled = false;

    setLoading(true);

    reportingService
      .getExecutiveWorkStreams({ dateFrom: from, dateTo: to })
      .then((result) => {
        if (cancelled) return;

        setData(result.streams);
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;

        setError(err?.response?.data?.message ?? 'Unable to load work orders for this range.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    // Returned so the effect below cancels a request whose range has already
    // changed: without it a slow reply for last month can land after a fast one
    // for today and overwrite it.
    return () => {
      cancelled = true;
    };
  }, [from, to]);

  useEffect(() => load(), [load, refreshToken]);

  return { data, loading, error };
};

export interface WorkStreamTile {
  label: string;
  value: number | null | undefined;
  tone?: 'success' | 'danger' | 'warning' | 'info' | 'neutral';
}

interface WorkStreamCardProps {
  title: string;
  subtitle?: React.ReactNode;
  icon?: React.ReactNode;
  /** Accepts a linked range so the card can offer its own re-link control. */
  range: WidgetRangeState | LinkedRangeState;
  loading: boolean;
  error: string | null;
  /** Null when no monitored schema models this stream. */
  available: boolean;
  headline: { label: string; value: number | null | undefined; caption?: React.ReactNode };
  tiles: WorkStreamTile[];
}

/** Narrows a range to the linked variety without a cast at every call site. */
const asLinked = (range: WidgetRangeState | LinkedRangeState): LinkedRangeState | null =>
  'linked' in range ? range : null;

const TONE_TEXT: Record<NonNullable<WorkStreamTile['tone']>, string> = {
  success: 'text-emerald-600 dark:text-emerald-400',
  danger: 'text-red-500 dark:text-red-400',
  warning: 'text-amber-500 dark:text-amber-400',
  info: 'text-blue-600 dark:text-blue-400',
  neutral: '',
};

const WorkStreamCard: React.FC<WorkStreamCardProps> = ({
  title,
  subtitle,
  icon,
  range,
  loading,
  error,
  available,
  headline,
  tiles,
}) => {
  const isDarkMode = useTheme();
  const linked = asLinked(range);
  const detached = linked !== null && !linked.linked;

  return (
    <Card flush>
      <CardHeader
        title={title}
        subtitle={subtitle}
        icon={icon}
        actions={
          <div className="flex flex-col items-end gap-1">
            <WidgetRange state={range} />

            {/* Only shown once this widget has actually stopped following the
                page control. Without it, an overridden widget is
                indistinguishable from a global filter that has stopped working —
                which is the bug report that arrives instead. */}
            {detached && (
              <button
                type="button"
                onClick={linked.relink}
                title="This widget is on its own range. Click to follow the dashboard range again."
                className={`inline-flex items-center gap-1 text-[10px] font-medium rounded px-1.5 py-0.5 transition-colors ${
                  isDarkMode
                    ? 'text-amber-400 hover:bg-gray-800'
                    : 'text-amber-600 hover:bg-amber-50'
                }`}
              >
                <Link2Off size={10} />
                own range · re-link
              </button>
            )}
          </div>
        }
      />
      <CardBody>
        <PanelState
          loading={loading && headline.value === undefined}
          error={error}
          empty={!available}
          emptyMessage="No monitored system records this queue."
          height={170}
        >
          <div>
            <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              {headline.label}
            </p>
            <p
              className={`text-3xl font-bold tracking-tight tabular-nums ${
                isDarkMode ? 'text-white' : 'text-gray-900'
              }`}
            >
              {formatNumber(headline.value)}
            </p>
            {headline.caption && (
              <p className={`text-[11px] mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                {headline.caption}
              </p>
            )}

            <div className="grid grid-cols-2 gap-2 mt-4">
              {tiles.map((tile) => (
                <div
                  key={tile.label}
                  className={`rounded-lg px-2.5 py-2 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
                >
                  <p className={`text-[11px] ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                    {tile.label}
                  </p>
                  <p
                    className={`text-base font-semibold tabular-nums ${
                      TONE_TEXT[tile.tone ?? 'neutral'] ||
                      (isDarkMode ? 'text-white' : 'text-gray-900')
                    }`}
                  >
                    {formatNumber(tile.value)}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </PanelState>
      </CardBody>
    </Card>
  );
};

export default WorkStreamCard;
