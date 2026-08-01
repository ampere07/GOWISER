import React from 'react';
import { useTheme } from '../../hooks/useTheme';
import { Granularity } from '../../types/reporting';

const ALL: { key: Granularity; label: string }[] = [
  { key: 'daily', label: 'Daily' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'monthly', label: 'Monthly' },
  { key: 'yearly', label: 'Yearly' },
];

interface PeriodTabsProps {
  value: Granularity;
  onChange: (period: Granularity) => void;
  /** Restrict the offered periods; order always follows daily → yearly. */
  options?: Granularity[];
  disabled?: boolean;
  size?: 'sm' | 'md';
}

/**
 * The Daily / Weekly / Monthly / Yearly segmented control.
 *
 * A button group rather than a <select> deliberately: it is the control users
 * reach for most on these pages, and the reference keeps all four visible so the
 * current period is readable without opening anything.
 *
 * Uses a neutral dark fill for the active segment instead of the brand colour.
 * The panels these sit in are dense with meaningful green/red/blue, and one more
 * coloured element competing with them made the period harder to spot, not
 * easier.
 */
const PeriodTabs: React.FC<PeriodTabsProps> = ({ value, onChange, options, disabled, size = 'md' }) => {
  const isDarkMode = useTheme();

  const items = options ? ALL.filter((item) => options.includes(item.key)) : ALL;

  const padding = size === 'sm' ? 'px-2.5 py-1 text-[11px]' : 'px-3.5 py-1.5 text-xs';

  return (
    <div
      role="group"
      aria-label="Reporting period"
      className={`inline-flex rounded-lg border overflow-hidden ${
        isDarkMode ? 'border-gray-700' : 'border-gray-300'
      } ${disabled ? 'opacity-50 pointer-events-none' : ''}`}
    >
      {items.map((item, index) => {
        const active = item.key === value;

        return (
          <button
            key={item.key}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(item.key)}
            className={`${padding} font-semibold transition-colors ${
              index > 0 ? (isDarkMode ? 'border-l border-gray-700' : 'border-l border-gray-300') : ''
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
  );
};

export default PeriodTabs;
