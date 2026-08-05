import React, { useCallback, useEffect, useRef, useState } from 'react';
import { CalendarDays, CalendarRange, CalendarClock, Lock, RotateCcw, SlidersHorizontal } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { usePermissions } from '../../hooks/usePermissions';
import { WidgetRangeState } from '../../hooks/useWidgetRange';
import { Granularity } from '../../types/reporting';
import { ACTION } from '../../types/rbac';

/**
 * The page-level range control, docked out of the content flow.
 *
 * Three horizons only — Daily, Weekly, Monthly — as icon buttons. Yearly is
 * deliberately absent: this bar is the quick switch, and a year of day-by-day
 * work orders is a report rather than a glance. The custom picker still reaches
 * any range from the widget controls in the card headers.
 *
 * Position is a genuine responsive difference, not a breakpoint tweak:
 *
 *   Desktop  a vertical rail pinned to the right edge, vertically centred. It
 *            sits beside the content rather than over it, so nothing is
 *            occluded, and it stays put while a long dashboard scrolls.
 *   Mobile   a horizontal bar stuck to the bottom, above the safe-area inset.
 *            A side rail on a phone would cover the content it is filtering,
 *            and the bottom edge is where a thumb already is.
 *
 * Labels are revealed rather than shown. On a pointer device the label appears
 * on hover or keyboard focus; on touch there is no hover, so a press-and-hold
 * reveals it without activating the button. Both paths also carry a `title` and
 * an `aria-label`, so the control is fully usable by a screen reader and by
 * anyone who never discovers either gesture.
 */

const PRESETS: { key: Granularity; label: string; icon: React.ReactNode; hint: string }[] = [
  { key: 'daily', label: 'Daily', icon: <CalendarClock size={17} />, hint: 'Today' },
  { key: 'weekly', label: 'Weekly', icon: <CalendarDays size={17} />, hint: 'Last 7 days' },
  { key: 'monthly', label: 'Monthly', icon: <CalendarRange size={17} />, hint: 'Month to date' },
];

/** How long a touch must be held before it counts as "show me the label". */
const HOLD_MS = 350;

interface FloatingRangeBarProps {
  state: WidgetRangeState;
  /** How many widgets have detached from this control, if any. */
  overriddenCount?: number;
  /** Puts every detached widget back under this control. */
  onRelinkAll?: () => void;
}

export const FloatingRangeBar: React.FC<FloatingRangeBarProps> = ({
  state,
  overriddenCount = 0,
  onRelinkAll,
}) => {
  const isDarkMode = useTheme();
  const { can } = usePermissions();

  const [revealed, setRevealed] = useState<string | null>(null);
  const holdTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Set by a completed hold so the click it precedes does not also fire — a
  // press-and-hold is a request to read the label, not to apply the range.
  const heldRef = useRef(false);

  const clearHold = useCallback(() => {
    if (holdTimer.current) {
      clearTimeout(holdTimer.current);
      holdTimer.current = null;
    }
  }, []);

  // A timer outliving the component would call setState on an unmounted tree.
  useEffect(() => clearHold, [clearHold]);

  const editable = can(ACTION.filtersModify);

  const startHold = (key: string) => {
    clearHold();
    heldRef.current = false;

    holdTimer.current = setTimeout(() => {
      heldRef.current = true;
      setRevealed(key);
    }, HOLD_MS);
  };

  const endHold = () => {
    clearHold();

    // Left up briefly so a hold-to-read does not vanish the instant the finger
    // lifts, which would make the label unreadable on the gesture that asked
    // for it.
    if (heldRef.current) {
      setTimeout(() => setRevealed(null), 900);
    }
  };

  const shell = isDarkMode
    ? 'bg-gray-900/95 border-gray-700 shadow-black/40'
    : 'bg-white/95 border-gray-200 shadow-gray-400/20';

  /**
   * A role without `action.filters.modify` gets the current range as static
   * text. A control that looks clickable and is not is worse than no control.
   */
  if (!editable) {
    return (
      <div
        className={`fixed z-30 backdrop-blur border shadow-lg
          bottom-0 inset-x-0 rounded-t-xl px-3 py-2 flex items-center justify-center gap-2
          lg:bottom-auto lg:inset-x-auto lg:right-3 lg:top-1/2 lg:-translate-y-1/2 lg:rounded-xl lg:flex-col ${shell}`}
        style={{ paddingBottom: 'calc(0.5rem + env(safe-area-inset-bottom, 0px))' }}
      >
        <Lock size={13} className={isDarkMode ? 'text-gray-500' : 'text-gray-400'} />
        <span className={`text-[11px] ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
          {state.range.from === state.range.to
            ? state.range.from
            : `${state.range.from} – ${state.range.to}`}
        </span>
      </div>
    );
  }

  return (
    <div
      // Bottom bar on phones, right-hand rail from `lg` up. `z-30` keeps it
      // under the session-expiry modal and the print overlay, which must never
      // be sat on top of by a filter.
      className={`fixed z-30 backdrop-blur border shadow-lg
        bottom-0 inset-x-0 rounded-t-xl px-3 py-2 flex flex-row items-center justify-center gap-1.5
        lg:bottom-auto lg:inset-x-auto lg:right-3 lg:top-1/2 lg:-translate-y-1/2
        lg:rounded-xl lg:flex-col lg:px-1.5 lg:py-2 ${shell}`}
      style={{ paddingBottom: 'calc(0.5rem + env(safe-area-inset-bottom, 0px))' }}
      role="group"
      aria-label="Dashboard date range"
    >
      <SlidersHorizontal
        size={13}
        className={`hidden lg:block mb-0.5 ${isDarkMode ? 'text-gray-600' : 'text-gray-300'}`}
        aria-hidden
      />

      {PRESETS.map((preset) => {
        const active = state.preset === preset.key;
        const showLabel = revealed === preset.key;

        return (
          <div key={preset.key} className="relative">
            <button
              type="button"
              aria-pressed={active}
              aria-label={`${preset.label} — ${preset.hint}`}
              title={`${preset.label} · ${preset.hint}`}
              onClick={() => {
                // Swallow the click that a completed press-and-hold generates.
                if (heldRef.current) {
                  heldRef.current = false;
                  return;
                }

                state.setPreset(preset.key);
              }}
              onMouseEnter={() => setRevealed(preset.key)}
              onMouseLeave={() => setRevealed((current) => (current === preset.key ? null : current))}
              onFocus={() => setRevealed(preset.key)}
              onBlur={() => setRevealed((current) => (current === preset.key ? null : current))}
              onTouchStart={() => startHold(preset.key)}
              onTouchEnd={endHold}
              onTouchCancel={endHold}
              className={`w-10 h-10 rounded-lg flex items-center justify-center transition-colors ${
                active
                  ? isDarkMode
                    ? 'bg-gray-100 text-gray-900'
                    : 'bg-gray-800 text-white'
                  : isDarkMode
                  ? 'text-gray-400 hover:bg-gray-800 hover:text-gray-100'
                  : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900'
              }`}
            >
              {preset.icon}
            </button>

            {/* Rendered rather than a CSS-only tooltip so touch can drive it too.
                Opens to the left on the desktop rail and above on the mobile bar,
                which is the side each has room on. */}
            {showLabel && (
              <span
                role="tooltip"
                className={`pointer-events-none absolute z-10 whitespace-nowrap rounded-md px-2 py-1 text-[11px] font-medium shadow-lg
                  bottom-full left-1/2 -translate-x-1/2 mb-1.5
                  lg:bottom-auto lg:left-auto lg:right-full lg:top-1/2 lg:-translate-x-0 lg:-translate-y-1/2 lg:mb-0 lg:mr-2 ${
                    isDarkMode ? 'bg-gray-100 text-gray-900' : 'bg-gray-900 text-white'
                  }`}
              >
                {preset.label}
                <span className="opacity-60"> · {preset.hint}</span>
              </span>
            )}
          </div>
        );
      })}

      {/* Only present when something has actually detached, so the bar stays
          three buttons wide in the ordinary case. */}
      {overriddenCount > 0 && onRelinkAll && (
        <div className="relative">
          <button
            type="button"
            onClick={onRelinkAll}
            aria-label={`Re-link ${overriddenCount} widget${overriddenCount === 1 ? '' : 's'} to this range`}
            title={`${overriddenCount} widget${
              overriddenCount === 1 ? '' : 's'
            } on their own range — click to re-link`}
            className={`w-10 h-10 rounded-lg flex items-center justify-center transition-colors relative ${
              isDarkMode
                ? 'text-amber-400 hover:bg-gray-800'
                : 'text-amber-600 hover:bg-amber-50'
            }`}
          >
            <RotateCcw size={16} />
            <span
              className={`absolute top-1 right-1 min-w-[14px] h-[14px] px-[3px] rounded-full text-[9px] font-bold flex items-center justify-center ${
                isDarkMode ? 'bg-amber-400 text-gray-900' : 'bg-amber-500 text-white'
              }`}
            >
              {overriddenCount}
            </span>
          </button>
        </div>
      )}

      <span
        className={`hidden lg:block text-[9px] text-center leading-tight mt-0.5 px-0.5 ${
          isDarkMode ? 'text-gray-600' : 'text-gray-400'
        }`}
      >
        {state.range.from === state.range.to ? 'today' : state.preset}
      </span>
    </div>
  );
};

export default FloatingRangeBar;
