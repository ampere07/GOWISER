import { useCallback, useMemo, useState } from 'react';
import { DateRange, Granularity, RangePreset } from '../types/reporting';
import { rangeForPreset, WidgetRangeState } from './useWidgetRange';

export interface LinkedRangeState extends WidgetRangeState {
  /** True while this widget is still following the page-level range. */
  linked: boolean;
  /** Puts the widget back under the page control. */
  relink: () => void;
}

/**
 * A widget range that follows the page's range until someone overrides it.
 *
 * The brief asks for two things that pull against each other: one control at the
 * top that moves everything, and per-widget shortcuts that move one thing. A
 * widget that only followed the global range could not be moved on its own; one
 * that never followed it would ignore the control that is supposed to drive the
 * page.
 *
 * So the widget is *linked* by default and detaches the moment its own control
 * is touched. After that the global control no longer reaches it — which is the
 * point of having overridden it — and the widget shows that it has detached with
 * a way back. Without that affordance an overridden widget looks identical to a
 * broken global filter, and the usual outcome is a bug report about the top
 * control "not working".
 *
 * Re-linking is also what the page-level reset does, so "put everything back" is
 * one click rather than one per widget.
 */
export const useLinkedRange = (global: WidgetRangeState): LinkedRangeState => {
  // Null means linked. Storing the override rather than a boolean keeps the two
  // pieces of state that must agree — "is it detached" and "what to" — as one.
  const [override, setOverride] = useState<{ preset: RangePreset; range: DateRange } | null>(null);

  const linked = override === null;

  const setPreset = useCallback((next: RangePreset) => {
    setOverride({
      preset: next,
      range: rangeForPreset(next === 'custom' ? 'monthly' : next),
    });
  }, []);

  const setCustom = useCallback((next: DateRange) => {
    // A reversed range is swapped rather than rejected, matching the backend and
    // the unlinked control: erroring on an obvious slip is worse than fixing it.
    const ordered = next.from > next.to ? { from: next.to, to: next.from } : next;

    setOverride({ preset: 'custom', range: ordered });
  }, []);

  const relink = useCallback(() => setOverride(null), []);

  const preset = override?.preset ?? global.preset;
  const range = override?.range ?? global.range;

  const granularity: Granularity = useMemo(
    () => (preset === 'custom' ? 'daily' : preset),
    [preset]
  );

  return {
    preset,
    range,
    granularity,
    setPreset,
    setCustom,
    // Dirty means "not showing what the page is showing", which for a linked
    // widget is whatever the page itself reports and for an unlinked one is
    // always true. That is what the re-link affordance keys off.
    dirty: !linked || global.dirty,
    reset: relink,
    linked,
    relink,
  };
};
