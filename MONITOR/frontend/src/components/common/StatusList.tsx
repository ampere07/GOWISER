import React from 'react';
import { useTheme } from '../../hooks/useTheme';

interface StatusListProps {
  /** Rendered in order; keys are shown as-is after prettifying. */
  items: Record<string, number> | undefined;
  loading?: boolean;
}

const prettify = (key: string): string =>
  key
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');

const StatusList: React.FC<StatusListProps> = ({ items, loading }) => {
  const isDarkMode = useTheme();
  const entries = Object.entries(items || {});

  if (entries.length === 0) {
    return (
      <p className={`text-sm ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}>
        {loading ? 'Loading...' : 'No data for this period.'}
      </p>
    );
  }

  return (
    <div className="space-y-0">
      {entries.map(([key, value]) => (
        <div
          key={key}
          className={`flex items-center justify-between py-3 px-2 border-b last:border-0 ${
            isDarkMode ? 'border-gray-700/50' : 'border-gray-300'
          }`}
        >
          <span className={`font-medium ${isDarkMode ? 'text-slate-400' : 'text-slate-600'}`}>
            {prettify(key)}
          </span>
          <span className={`text-lg font-bold ${isDarkMode ? 'text-slate-200' : 'text-slate-900'}`}>
            {loading && value === undefined ? '...' : (value ?? 0).toLocaleString()}
          </span>
        </div>
      ))}
    </div>
  );
};

export default StatusList;
