import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  CalendarClock,
  CalendarDays,
  CalendarRange,
  Maximize2,
  Minimize2,
  RefreshCw,
  RotateCcw,
  SlidersHorizontal,
  Sun,
} from 'lucide-react';
import { Button, Pill, useControlClass } from './primitives';
import { useTheme } from '../../hooks/useTheme';
import { usePermissions } from '../../hooks/usePermissions';
import { WidgetRangeState } from '../../hooks/useWidgetRange';
import { Granularity } from '../../types/reporting';
import { ACTION } from '../../types/rbac';

/**
 * The controls every reporting page carries at the top: a period selector that
 * governs the whole screen, full screen, and refresh.
 *
 * ── Why these moved off the Group Overview ────────────────────────────
 *
 * They were built there and stayed there, so the executive view had one control
 * that moved everything while the four module pages had a range control in each
 * card header and nothing above them. Two consequences, both bad. Setting a
 * module page to "this month" meant touching six widgets in turn and hoping none
 * was missed — and a page whose widgets are on six different windows produces
 * figures that do not add up, with nothing on screen saying why. And an operator
 * who learned the Group Overview arrived on Financial to find the same job done
 * a different way.
 *
 * The per-widget controls stay. They are the reason the page-level one is
 * *linked* rather than absolute: a widget follows the page until somebody moves
 * it, and says so afterwards — see useLinkedRange. One control that moves
 * everything, and per-panel exceptions where a comparison needs them.
 */

/** The four horizons, each with the icon it compresses to when the bar sticks. */
const PRESETS: { key: Granularity; label: string; icon: React.ElementType }[] = [
  { key: 'daily', label: 'Daily', icon: Sun },
  { key: 'weekly', label: 'Weekly', icon: CalendarRange },
  { key: 'monthly', label: 'Monthly', icon: CalendarDays },
  { key: 'yearly', label: 'Yearly', icon: CalendarClock },
];

export interface PageChrome {
  /** Goes on the element that should fill the screen. */
  container: React.RefObject<HTMLDivElement | null>;
  /** Goes immediately above the period bar; see the note in PagePeriodBar. */
  sentinel: React.RefObject<HTMLDivElement | null>;
  isFullscreen: boolean;
  toggleFullscreen: () => void;
  /** True once the period bar has reached the top of the scroll container. */
  stuck: boolean;
  /** Class for the container while full screen, so the page keeps its surface. */
  containerClass: string;
}

/**
 * Full-screen state and sticky detection for a reporting page.
 *
 * Sticky is measured with an IntersectionObserver on a zero-height sentinel
 * rather than a scroll listener: the observer fires only when the boundary is
 * actually crossed, where a handler runs on every frame of every scroll to
 * answer the same yes/no question — on a page already re-rendering six panels.
 */
export const usePageChrome = (): PageChrome => {
  const isDarkMode = useTheme();

  const container = useRef<HTMLDivElement>(null);
  const sentinel = useRef<HTMLDivElement>(null);

  const [isFullscreen, setIsFullscreen] = useState(false);
  const [stuck, setStuck] = useState(false);

  const toggleFullscreen = useCallback(() => {
    if (!container.current) return;

    if (!document.fullscreenElement) {
      // Swallowed rather than surfaced: a browser refusing full screen (an
      // iframe without the permission, a policy) is not something the reader
      // can act on, and an error banner over a working page is worse.
      container.current.requestFullscreen().catch(() => undefined);
    } else {
      document.exitFullscreen();
    }
  }, []);

  useEffect(() => {
    // Driven by the event rather than by the click, so pressing Escape — which
    // exits full screen without touching our button — still updates the icon.
    const onChange = () => setIsFullscreen(Boolean(document.fullscreenElement));

    document.addEventListener('fullscreenchange', onChange);
    return () => document.removeEventListener('fullscreenchange', onChange);
  }, []);

  useEffect(() => {
    const element = sentinel.current;

    if (!element || typeof IntersectionObserver === 'undefined') return;

    const observer = new IntersectionObserver(([entry]) => setStuck(!entry.isIntersecting), {
      threshold: 0,
    });

    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  return {
    container,
    sentinel,
    isFullscreen,
    toggleFullscreen,
    stuck,
    containerClass: isFullscreen
      ? isDarkMode
        ? 'bg-gray-950 overflow-y-auto h-screen'
        : 'bg-gray-50 overflow-y-auto h-screen'
      : '',
  };
};

/**
 * Full Screen and Refresh, for a page header's `actions` slot.
 *
 * `extra` takes anything page-specific — Print on Financial, Edit Layout on the
 * Group Overview — so those sit in the same row rather than each page inventing
 * its own arrangement.
 */
export const PageActions: React.FC<{
  chrome: PageChrome;
  onRefresh: () => void;
  refreshing?: boolean;
  extra?: React.ReactNode;
}> = ({ chrome, onRefresh, refreshing = false, extra }) => (
  <div className="flex flex-wrap items-center gap-2">
    {extra}

    <Button
      icon={chrome.isFullscreen ? <Minimize2 size={15} /> : <Maximize2 size={15} />}
      onClick={chrome.toggleFullscreen}
      title={chrome.isFullscreen ? 'Exit full screen' : 'Full screen'}
    >
      {chrome.isFullscreen ? 'Exit' : 'Full Screen'}
    </Button>

    <Button
      icon={<RefreshCw size={15} className={refreshing ? 'animate-spin' : ''} />}
      onClick={onRefresh}
      disabled={refreshing}
    >
      Refresh
    </Button>
  </div>
);

/**
 * The page-wide period selector.
 *
 * Sticky, because it governs everything below it: on a page four panels deep,
 * scrolling to the last one used to mean scrolling back up to find out which
 * period you were reading — and the commonest way to misread any of these
 * screens is to read a figure without its window.
 *
 * Compressed to icons once it sticks. Pinned at full height the bar costs a
 * fifth of a laptop viewport permanently, which on a page of dense panels is the
 * wrong thing to spend the room on. Every icon keeps its name as a tooltip and
 * as its accessible label, so nothing is ever only a picture.
 *
 * Behind `action.filters.modify`, as the per-widget control is: a role without
 * it sees the applied window as static text rather than a control that looks
 * clickable and is not.
 */
export const PagePeriodBar: React.FC<{
  chrome: PageChrome;
  state: WidgetRangeState;
  /** Rendered on the right — a database filter, a source notice, a count. */
  trailing?: React.ReactNode;
}> = ({ chrome, state, trailing }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();
  const { can } = usePermissions();

  const { stuck } = chrome;
  const editable = can(ACTION.filtersModify);

  const label =
    state.range.from === state.range.to
      ? state.range.from
      : `${state.range.from} – ${state.range.to}`;

  return (
    <>
      {/* Zero-height marker immediately above the bar. Its leaving the viewport
          is what "the bar has reached the top" means — measured, rather than
          inferred from a scroll offset that would have to know the height of
          everything above it. */}
      <div ref={chrome.sentinel} aria-hidden className="h-px -mb-px" />

      <div
        className={`sticky top-0 z-30 flex flex-wrap items-center gap-2 rounded-xl border transition-all duration-200 ${
          stuck ? 'px-2 py-1.5 shadow-md backdrop-blur' : 'px-3 py-2.5'
        } ${
          isDarkMode
            ? `border-gray-800 ${stuck ? 'bg-gray-900/95' : 'bg-gray-900'}`
            : `border-gray-200 ${stuck ? 'bg-white/95' : 'bg-white'}`
        }`}
      >
        <span
          className={`text-xs font-bold uppercase tracking-wider mr-1 ${stuck ? 'sr-only' : ''} ${
            isDarkMode ? 'text-gray-500' : 'text-gray-400'
          }`}
        >
          Period
        </span>

        {editable ? (
          <>
            <div
              className={`inline-flex rounded-lg p-0.5 ${isDarkMode ? 'bg-gray-950' : 'bg-gray-100'}`}
              role="radiogroup"
              aria-label="Page date range"
            >
              {PRESETS.map((option) => {
                const active = state.preset === option.key;
                const Icon = option.icon;

                return (
                  <button
                    key={option.key}
                    type="button"
                    role="radio"
                    aria-checked={active}
                    aria-label={option.label}
                    title={option.label}
                    onClick={() => state.setPreset(option.key)}
                    className={`flex items-center gap-1.5 rounded-md text-sm font-bold transition-all ${
                      stuck ? 'px-2 py-1' : 'px-3.5 py-1.5'
                    } ${
                      active
                        ? isDarkMode
                          ? 'bg-blue-500/20 text-blue-300'
                          : 'bg-white text-blue-700 shadow-sm'
                        : isDarkMode
                        ? 'text-gray-400 hover:text-gray-200'
                        : 'text-gray-600 hover:text-gray-900'
                    }`}
                  >
                    <Icon size={15} className={stuck ? '' : 'hidden'} />
                    {/* Hidden rather than unmounted, so the control keeps its
                        accessible name when the bar compresses. */}
                    <span className={stuck ? 'sr-only' : ''}>{option.label}</span>
                  </button>
                );
              })}
            </div>

            <button
              type="button"
              role="radio"
              aria-checked={state.preset === 'custom'}
              aria-label="Custom Range"
              title="Custom Range"
              onClick={() => state.setPreset('custom')}
              className={`flex items-center gap-1.5 rounded-lg border text-sm font-bold transition-all ${
                stuck ? 'px-2 py-1' : 'px-3.5 py-1.5'
              } ${
                state.preset === 'custom'
                  ? 'border-blue-500 bg-blue-500/10 text-blue-700 dark:text-blue-300'
                  : isDarkMode
                  ? 'border-gray-700 text-gray-300 hover:border-gray-600'
                  : 'border-gray-300 text-gray-700 hover:border-gray-400'
              }`}
            >
              <SlidersHorizontal size={15} className={stuck ? '' : 'hidden'} />
              <span className={stuck ? 'sr-only' : ''}>Custom Range</span>
            </button>

            {/* The date inputs survive compression: they are the only controls
                here holding a value rather than a choice, and hiding them would
                take a custom range off screen the moment somebody scrolled.

                Applied on change rather than on a button, unlike the per-widget
                control: this is one field pair rather than a staged form, and
                the range is clamped so a half-typed year cannot be requested. */}
            {state.preset === 'custom' && (
              <span className="flex items-center gap-1.5">
                <input
                  type="date"
                  value={state.range.from}
                  max={state.range.to || undefined}
                  onChange={(event) =>
                    event.target.value &&
                    state.setCustom({ from: event.target.value, to: state.range.to })
                  }
                  className={`${controlClass} tabular-nums`}
                  aria-label="Range start"
                />
                <span className={isDarkMode ? 'text-gray-500' : 'text-gray-400'}>→</span>
                <input
                  type="date"
                  value={state.range.to}
                  min={state.range.from || undefined}
                  onChange={(event) =>
                    event.target.value &&
                    state.setCustom({ from: state.range.from, to: event.target.value })
                  }
                  className={`${controlClass} tabular-nums`}
                  aria-label="Range end"
                />
              </span>
            )}

            {state.dirty && (
              <Button
                icon={<RotateCcw size={13} />}
                onClick={state.reset}
                title="Back to the default period"
              >
                {stuck ? '' : 'Reset'}
              </Button>
            )}
          </>
        ) : (
          <span className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
            {label}
          </span>
        )}

        <span className="ml-auto flex items-center gap-2">
          {trailing}
          {/* The applied window never compresses away — a pinned bar that no
              longer says which period is worse than no bar, because it still
              looks authoritative. */}
          <Pill tone="info">{label}</Pill>
        </span>
      </div>
    </>
  );
};

export default PagePeriodBar;
