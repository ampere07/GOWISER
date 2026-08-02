import React, { useEffect, useState } from 'react';
import { Filter, Lock, X } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { usePermissions } from '../../hooks/usePermissions';
import { WidgetRangeState } from '../../hooks/useWidgetRange';
import { Granularity } from '../../types/reporting';
import { ACTION } from '../../types/rbac';
import { Button, useControlClass } from './primitives';

/**
 * The four horizons, always all four and always in this order.
 *
 * Custom is deliberately not among them. It is not a fifth horizon — it has no
 * length of its own — and putting it in the segmented group made the group five
 * wide and pushed the four that matter into abbreviations.
 */
const PRESETS: { key: Granularity; label: string }[] = [
  { key: 'daily', label: 'Daily' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'monthly', label: 'Monthly' },
  { key: 'yearly', label: 'Yearly' },
];

interface WidgetRangeProps {
  state: WidgetRangeState;
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
 * Two parts, and the split matters. The segmented Daily/Weekly/Monthly/Yearly
 * group is the control people reach for constantly, so all four stay visible
 * with the active one filled dark — readable at a glance without opening
 * anything. Custom is a separate button that reveals a staged date range,
 * because picking arbitrary dates is rare and the inputs would otherwise take
 * permanent space in every card header on the page.
 *
 * Behind `action.filters.modify`. A role without it sees the current range as
 * static text rather than a disabled dropdown — a control that looks clickable
 * and is not is worse than no control.
 */
export const WidgetRange: React.FC<WidgetRangeProps> = ({ state, size = 'sm' }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();
  const { can } = usePermissions();

  const [draft, setDraft] = useState(state.range);
  const [open, setOpen] = useState(state.preset === 'custom');

  // Re-sync when the range changes from elsewhere — a preset switch, or a reset.
  useEffect(() => setDraft(state.range), [state.range]);
  useEffect(() => {
    if (state.preset === 'custom') setOpen(true);
  }, [state.preset]);

  const editable = can(ACTION.filtersModify);
  const padding = size === 'sm' ? 'px-3 py-1 text-[11px]' : 'px-3.5 py-1.5 text-xs';

  const rangeLabel =
    state.range.from === state.range.to
      ? state.range.from
      : `${state.range.from} – ${state.range.to}`;

  if (!editable) {
    return (
      <span
        className={`inline-flex items-center gap-1.5 text-[11px] ${
          isDarkMode ? 'text-gray-500' : 'text-gray-500'
        }`}
        title="Your role cannot change widget date ranges"
      >
        <Lock size={11} />
        {rangeLabel}
      </span>
    );
  }

  return (
    <div className="flex flex-col items-end gap-1.5">
      <div className="flex flex-wrap items-center gap-1.5 justify-end">
        {/* Neutral dark fill for the active segment rather than the brand
            colour: these panels are already dense with meaningful green, red
            and blue, and one more coloured element competing with them made the
            active period harder to spot, not easier. */}
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
                onClick={() => {
                  state.setPreset(item.key);
                  setOpen(false);
                }}
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
                {item.label}
              </button>
            );
          })}
        </div>

        <button
          type="button"
          aria-pressed={state.preset === 'custom'}
          onClick={() => setOpen((current) => !current)}
          title="Pick an exact date range"
          className={`${padding} font-semibold rounded-lg border transition-colors ${
            state.preset === 'custom'
              ? isDarkMode
                ? 'bg-gray-200 text-gray-900 border-gray-200'
                : 'bg-gray-700 text-white border-gray-700'
              : isDarkMode
              ? 'bg-gray-900 text-gray-300 border-gray-700 hover:bg-gray-800'
              : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
          }`}
        >
          Custom Range
        </button>
      </div>

      {open && (
        <div className="flex flex-wrap items-center gap-1.5 justify-end">
          <span className={`text-xs font-medium ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
            Period:
          </span>

          <input
            type="date"
            value={draft.from}
            max={draft.to || undefined}
            onChange={(event) => setDraft({ ...draft, from: event.target.value })}
            onKeyDown={(event) => event.key === 'Enter' && state.setCustom(draft)}
            className={`${controlClass} !py-1 !text-xs`}
            aria-label="Custom range start"
          />
          <input
            type="date"
            value={draft.to}
            min={draft.from || undefined}
            onChange={(event) => setDraft({ ...draft, to: event.target.value })}
            onKeyDown={(event) => event.key === 'Enter' && state.setCustom(draft)}
            className={`${controlClass} !py-1 !text-xs`}
            aria-label="Custom range end"
          />

          {/* What is actually applied, beside the inputs that would change it —
              so a staged edit is visibly not yet in effect. */}
          <span className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            {rangeLabel}
          </span>

          {/* Applied on the button, never on change: a half-typed date fires
              change events, so applying live would request ranges like
              0002-01-01 while someone types a year. */}
          <Button
            variant="primary"
            icon={<Filter size={13} />}
            onClick={() => state.setCustom(draft)}
            title="Apply this date range"
            className={
              draft.from !== state.range.from || draft.to !== state.range.to
                ? '!py-1 ring-2 ring-blue-500/40'
                : '!py-1'
            }
          />
          <Button
            variant="outline"
            icon={<X size={13} />}
            onClick={() => {
              state.reset();
              setOpen(false);
            }}
            title="Reset this widget to its default range"
            className="!py-1"
          />
        </div>
      )}
    </div>
  );
};

export default WidgetRange;
