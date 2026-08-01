import React from 'react';
import { AlertTriangle, Database, Layers } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { AggregateInfo, ALL_DATABASES } from '../../types/reporting';
import { pluralise } from '../../utils/format';
import { useControlClass } from './primitives';

interface DatabaseFilterProps {
  value: string;
  onChange: (database: string) => void;
  /** Databases that can serve this section. */
  options: { key: string; label: string }[];
}

/**
 * Which database a section reads: one of them, or all of them merged.
 *
 * Only rendered when there is more than one to choose between — with a single
 * database there is nothing to pick, and an "All databases" option over one
 * database would be a distinction without a difference.
 */
export const DatabaseFilter: React.FC<DatabaseFilterProps> = ({ value, onChange, options }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  if (options.length < 2) {
    return null;
  }

  return (
    <label className="flex items-center gap-1.5">
      <Database size={14} className={isDarkMode ? 'text-gray-400' : 'text-gray-500'} />
      <span className="sr-only">Database</span>
      <select
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className={controlClass}
        title="Which database these figures come from"
      >
        <option value={ALL_DATABASES}>All databases ({options.length})</option>
        {options.map((option) => (
          <option key={option.key} value={option.key}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  );
};

/**
 * States that the figures on screen are a merge, and — the part that matters —
 * whether any database is missing from them.
 *
 * A total that is quietly short is worse than no total, so an unreachable
 * database is named rather than logged and forgotten.
 */
export const AggregateNotice: React.FC<{ aggregate?: AggregateInfo }> = ({ aggregate }) => {
  const isDarkMode = useTheme();

  if (!aggregate?.is_aggregate) {
    return null;
  }

  const answered = aggregate.answered.length;
  const failed = aggregate.failed;

  if (failed.length === 0) {
    return (
      <div
        className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
          isDarkMode
            ? 'border-blue-800/60 bg-blue-500/10 text-blue-200'
            : 'border-blue-200 bg-blue-50 text-blue-800'
        }`}
      >
        <Layers size={15} className="mt-0.5 flex-shrink-0" />
        <span>
          Combined from {pluralise(answered, 'database')}: {aggregate.answered_labels.join(', ')}.
        </span>
      </div>
    );
  }

  return (
    <div
      className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
        isDarkMode
          ? 'border-amber-800/60 bg-amber-500/10 text-amber-200'
          : 'border-amber-200 bg-amber-50 text-amber-800'
      }`}
    >
      <AlertTriangle size={15} className="mt-0.5 flex-shrink-0" />
      <div className="min-w-0">
        <p>
          <strong>
            {answered} of {aggregate.total_databases} databases
          </strong>{' '}
          answered, so these totals are incomplete.
        </p>
        <ul className="mt-1 space-y-0.5">
          {failed.map((entry) => (
            <li key={entry.key} className="break-words">
              <strong>{entry.label}</strong> — {entry.error}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
};

/**
 * The database a row came from, shown as a table cell in aggregate mode.
 *
 * Rows about a person or an account cannot be summed, so they are concatenated;
 * without this column a fleet-wide list of overdue accounts would give no
 * indication of who has to chase each one.
 */
export const SourceCell: React.FC<{ label?: string }> = ({ label }) => {
  const isDarkMode = useTheme();

  return (
    <span className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
      {label || '—'}
    </span>
  );
};

export default DatabaseFilter;
