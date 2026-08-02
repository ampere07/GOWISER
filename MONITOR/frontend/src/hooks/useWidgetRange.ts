import { useCallback, useMemo, useState } from 'react';
import { DateRange, Granularity, RangePreset } from '../types/reporting';

/**
 * Turns a preset into concrete dates, anchored on today.
 *
 * Weekly is the last seven days rather than the calendar week, matching
 * ReportPeriod::dashboardWindow on the backend. The two source systems genuinely
 * disagree about this — financial.php uses the calendar week, dashboard_stats.php
 * uses a rolling seven days — and the widget control is the dashboard's, so it
 * follows the dashboard's rule. The Financial module's four-horizon table still
 * shows the calendar week, and says so in its own labels.
 */
export const rangeForPreset = (preset: Exclude<RangePreset, 'custom'>): DateRange => {
  const now = new Date();

  const iso = (date: Date): string => {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
  };

  const today = iso(now);

  switch (preset) {
    case 'weekly': {
      const from = new Date(now);
      from.setDate(from.getDate() - 6);

      return { from: iso(from), to: today };
    }
    case 'monthly':
      return { from: `${today.slice(0, 7)}-01`, to: today };
    case 'yearly':
      return { from: `${now.getFullYear()}-01-01`, to: today };
    case 'daily':
    default:
      return { from: today, to: today };
  }
};

export interface WidgetRangeState {
  preset: RangePreset;
  range: DateRange;
  /** Granularity the backend should bucket a trend by, derived from the preset. */
  granularity: Granularity;
  setPreset: (preset: RangePreset) => void;
  setCustom: (range: DateRange) => void;
  /** True when this widget is no longer on its default range. */
  dirty: boolean;
  reset: () => void;
}

/**
 * One widget's own date range.
 *
 * This is what replaced the page-level period filter. Every widget owns its
 * window, so an executive can hold revenue on the year while putting the backlog
 * chart on today — the comparison the old single filter made impossible.
 *
 * The cost is that widgets on different ranges issue different requests. That is
 * cheaper than it looks: reportingService caches by parameter set for ten
 * seconds and the server caches per parameter bucket for sixty, so every widget
 * still sitting on the page default collapses into one HTTP request and one
 * query. Only a widget someone has actually moved pays for itself.
 */
export const useWidgetRange = (
  initial: RangePreset = 'monthly',
  initialRange?: DateRange
): WidgetRangeState => {
  const [preset, setPresetState] = useState<RangePreset>(initial);
  const [custom, setCustomState] = useState<DateRange>(
    () => initialRange ?? rangeForPreset(initial === 'custom' ? 'monthly' : initial)
  );

  const range = useMemo(
    () => (preset === 'custom' ? custom : rangeForPreset(preset)),
    [preset, custom]
  );

  const setPreset = useCallback((next: RangePreset) => {
    setPresetState(next);

    // Seed the custom inputs from whatever was on screen, so switching to
    // Custom opens on the dates the user was already looking at rather than
    // on two blank fields.
    if (next !== 'custom') {
      setCustomState(rangeForPreset(next));
    }
  }, []);

  const setCustom = useCallback((next: DateRange) => {
    // A reversed range is swapped rather than rejected, as the backend does:
    // erroring on an obvious slip is worse than just fixing it.
    const ordered = next.from > next.to ? { from: next.to, to: next.from } : next;

    setCustomState(ordered);
    setPresetState('custom');
  }, []);

  const reset = useCallback(() => {
    setPresetState(initial);
    setCustomState(rangeForPreset(initial === 'custom' ? 'monthly' : initial));
  }, [initial]);

  return {
    preset,
    range,
    // Daily and weekly windows are too short for anything but day buckets;
    // asking for monthly buckets over seven days yields a one-point chart.
    granularity: preset === 'custom' ? 'daily' : preset,
    setPreset,
    setCustom,
    dirty: preset !== initial,
    reset,
  };
};
