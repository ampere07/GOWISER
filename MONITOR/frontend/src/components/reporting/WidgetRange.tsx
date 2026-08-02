import React, { useEffect, useState } from 'react';
import { CalendarRange, Check, Lock } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { usePermissions } from '../../hooks/usePermissions';
import { WidgetRangeState } from '../../hooks/useWidgetRange';
import { RangePreset } from '../../types/reporting';
import { ACTION } from '../../types/rbac';
import { useControlClass } from './primitives';

const PRESETS: { key: RangePreset; label: string; short: string }[] = [
  { key: 'daily', label: 'Daily', short: 'D' },
  { key: 'weekly', label: 'Weekly', short: 'W' },
  { key: 'monthly', label: 'Monthly', short: 'M' },
  { key: 'yearly', label: 'Yearly', short: 'Y' },
  { key: 'custom', label: 'Custom Range', short: 'C' },
];

interface WidgetRangeProps {
  state: WidgetRangeState;
  /** Compact form for cards whose header is already crowded. */
  size?: 'sm' | 'md';
}

/**
 * The date-range control that lives inside every widget header.
 *
 * Replaces the single page-level period filter. Each widget carries its own, so
 * two panels on one screen can sit on different windows — which is the point:
 * comparing this month's collections against the twelve-month trend was
 * impossible when one control drove both.
 *
 * Behind `action.filters.modify`. A role without it sees the current range
 * rendered as static text rather than as a disabled dropdown, because a control
 * that looks clickable and is not is worse than no control.
 */
export const WidgetRange: React.FC<WidgetRangeProps> = ({ state, size = 'sm' }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();
  const { can } = usePermissions();

  const [draft, setDraft] = useState(state.range);

  // Re-sync when the range changes from elsewhere — a preset switch, or a reset.
  useEffect(() => setDraft(state.range), [state.range]);

  const editable = can(ACTION.filtersModify);
  const padding = size === 'sm' ? 'px-2 py-1 text-[11px]' : 'px-3 py-1.5 text-xs';

  if (!editable) {
    return (
      <span
        className={`inline-flex items-center gap-1.5 text-[11px] ${
          isDarkMode ? 'text-gray-500' : 'text-gray-500'
        }`}
        title="Your role cannot change widget date ranges"
      >
        <Lock size={11} />
        {state.range.from === state.range.to
          ? state.range.from
          : `${state.range.from} – ${state.range.to}`}
      </span>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-1.5 justify-end">
      <div
        role="group"
        aria-label="Widget date range"
        className={`inline-flex rounded-lg border overflow-hidden ${
          isDarkMode ? 'border-gray-700' : 'border-gray-300'
        }`}
      >
        {PRESETS.map((item, index) => {
          const active = item.key === state.preset;

          return (
            <button
              key={item.key}
              type="button"
              aria-pressed={active}
              title={item.label}
              onClick={() => state.setPreset(item.key)}
              className={`${padding} font-semibold transition-colors ${
                index > 0
                  ? isDarkMode
                    ? 'border-l border-gray-700'
                    : 'border-l border-gray-300'
                  : ''
              } ${
                active
                  ? isDarkMode
                    ? 'bg-gray-200 text-gray-900'
                    : 'bg-gray-700 text-white'
                  : isDarkMode
                  ? 'bg-gray-900 text-gray-300 hover:bg-gray-800'
                  : 'bg-white text-gray-700 hover:bg-gray-50'
              }`}
            >
              {/* Full labels crowd a card header beside a title; the initial is
                  enough once the group is recognisable, and `title` carries the
                  word for anyone who needs it. */}
              <span className="hidden lg:inline">{item.label.replace(' Range', '')}</span>
              <span className="lg:hidden">{item.short}</span>
            </button>
          );
        })}
      </div>

      {state.preset === 'custom' && (
        <span className="inline-flex items-center gap-1">
          <CalendarRange size={13} className={isDarkMode ? 'text-gray-500' : 'text-gray-400'} />
          <input
            type="date"
            value={draft.from}
            max={draft.to || undefined}
            onChange={(event) => setDraft({ ...draft, from: event.target.value })}
            className={`${controlClass} !py-1 !text-[11px]`}
            aria-label="Custom range start"
          />
          <input
            type="date"
            value={draft.to}
            min={draft.from || undefined}
            onChange={(event) => setDraft({ ...draft, to: event.target.value })}
            className={`${controlClass} !py-1 !text-[11px]`}
            aria-label="Custom range end"
          />
          {/* Applied on the tick, not on change: a half-typed date fires change
              events, so applying live would request ranges like 0002-01-01 while
              someone types a year. */}
          <button
            type="button"
            title="Apply this range"
            onClick={() => state.setCustom(draft)}
            className={`rounded-lg border p-1 transition-colors ${
              isDarkMode
                ? 'border-gray-700 text-gray-200 hover:bg-gray-800'
                : 'border-gray-300 text-gray-700 hover:bg-gray-50'
            }`}
          >
            <Check size={13} />
          </button>
        </span>
      )}
    </div>
  );
};

export default WidgetRange;
