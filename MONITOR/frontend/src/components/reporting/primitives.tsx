import React from 'react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';

/**
 * Small pieces every reporting panel needs: table chrome, the three data states,
 * bars and pills. Collected here so a table on the Dashboard and a table on the
 * Reports page cannot drift apart.
 */

// ── Tables ─────────────────────────────────────────────────────────────

export const Table: React.FC<{ children: React.ReactNode; className?: string }> = ({
  children,
  className = '',
}) => (
  // Horizontal scroll on the wrapper, not the page: these tables have eight
  // columns and must stay readable on a phone without the whole layout sliding.
  <div className="overflow-x-auto">
    <table className={`w-full text-sm ${className}`}>{children}</table>
  </div>
);

export const Thead: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const isDarkMode = useTheme();

  return (
    <thead className={isDarkMode ? 'bg-gray-800/50' : 'bg-gray-50'}>
      <tr>{children}</tr>
    </thead>
  );
};

interface ThProps {
  children?: React.ReactNode;
  align?: 'left' | 'right' | 'center';
  className?: string;
  width?: string;
}

export const Th: React.FC<ThProps> = ({ children, align = 'left', className = '', width }) => {
  const isDarkMode = useTheme();

  return (
    <th
      style={width ? { width } : undefined}
      className={`px-4 py-3 font-semibold whitespace-nowrap text-${align} ${
        isDarkMode ? 'text-gray-300' : 'text-gray-700'
      } ${className}`}
    >
      {children}
    </th>
  );
};

export const Tr: React.FC<{ children: React.ReactNode; className?: string }> = ({
  children,
  className = '',
}) => {
  const isDarkMode = useTheme();

  return (
    <tr className={`border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-100'} ${className}`}>
      {children}
    </tr>
  );
};

interface TdProps {
  children?: React.ReactNode;
  align?: 'left' | 'right' | 'center';
  className?: string;
  colSpan?: number;
}

export const Td: React.FC<TdProps> = ({ children, align = 'left', className = '', colSpan }) => (
  <td colSpan={colSpan} className={`px-4 py-3 text-${align} ${className}`}>
    {children}
  </td>
);

/** Totals row: same columns, but visually closed off from the data above. */
export const TotalRow: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const isDarkMode = useTheme();

  return (
    <tr
      className={`border-t-2 font-bold ${
        isDarkMode ? 'border-gray-700 bg-gray-800/40' : 'border-gray-200 bg-gray-50'
      }`}
    >
      {children}
    </tr>
  );
};

// ── Data states ────────────────────────────────────────────────────────

/**
 * Empty, loading and error inside a table body.
 *
 * One component for all three so a panel can never show "no data" while a
 * request is still in flight — the commonest way an empty state lies.
 */
export const TableState: React.FC<{
  colSpan: number;
  loading?: boolean;
  error?: string | null;
  empty?: boolean;
  emptyMessage?: string;
}> = ({ colSpan, loading, error, empty, emptyMessage = 'No data for this period.' }) => {
  const isDarkMode = useTheme();

  if (!loading && !error && !empty) {
    return null;
  }

  return (
    <tr>
      <td colSpan={colSpan} className="px-4 py-10 text-center">
        {loading ? (
          <span
            className={`inline-flex items-center gap-2 text-sm ${
              isDarkMode ? 'text-gray-400' : 'text-gray-500'
            }`}
          >
            <Loader2 size={14} className="animate-spin" />
            Loading…
          </span>
        ) : error ? (
          <span className="inline-flex items-center gap-2 text-sm text-red-500">
            <AlertTriangle size={14} />
            {error}
          </span>
        ) : (
          <span className={`text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            {emptyMessage}
          </span>
        )}
      </td>
    </tr>
  );
};

/** The same three states outside a table — for chart panels. */
export const PanelState: React.FC<{
  loading?: boolean;
  error?: string | null;
  empty?: boolean;
  emptyMessage?: string;
  height?: number;
  children: React.ReactNode;
}> = ({ loading, error, empty, emptyMessage = 'No data for this period.', height = 260, children }) => {
  const isDarkMode = useTheme();

  if (!loading && !error && !empty) {
    return <>{children}</>;
  }

  return (
    <div className="flex items-center justify-center text-center px-4" style={{ height }}>
      {loading ? (
        <span
          className={`inline-flex items-center gap-2 text-sm ${
            isDarkMode ? 'text-gray-400' : 'text-gray-500'
          }`}
        >
          <Loader2 size={14} className="animate-spin" />
          Loading…
        </span>
      ) : error ? (
        <span className="inline-flex items-center gap-2 text-sm text-red-500">
          <AlertTriangle size={14} />
          {error}
        </span>
      ) : (
        <span className={`text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          {emptyMessage}
        </span>
      )}
    </div>
  );
};

/** Page-level error banner. */
export const ErrorBanner: React.FC<{ message: string }> = ({ message }) => (
  <div className="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 flex items-center gap-3">
    <AlertTriangle className="h-4 w-4 flex-shrink-0" />
    <p className="font-medium text-sm">{message}</p>
  </div>
);

// ── Bars and pills ─────────────────────────────────────────────────────

/**
 * Proportion bar. Always renders its track, so a 0% row still occupies the same
 * width as a 100% one and the column stays scannable.
 */
export const Bar: React.FC<{ pct: number; color?: string; width?: number; height?: number }> = ({
  pct,
  color = '#0d6efd',
  width = 70,
  height = 6,
}) => {
  const isDarkMode = useTheme();
  const clamped = Math.max(0, Math.min(100, Number.isFinite(pct) ? pct : 0));

  return (
    <span
      className={`inline-block rounded-full overflow-hidden align-middle ${
        isDarkMode ? 'bg-gray-700' : 'bg-gray-200'
      }`}
      style={{ width, height }}
      role="presentation"
    >
      <span
        className="block h-full rounded-full transition-all duration-500"
        style={{ width: `${clamped}%`, backgroundColor: color }}
      />
    </span>
  );
};

export const Dot: React.FC<{ color: string }> = ({ color }) => (
  <span
    className="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0"
    style={{ backgroundColor: color }}
  />
);

/**
 * Rank badge. Gold, silver and bronze for the top three; the reference uses this
 * to make a league table readable without reading the numbers.
 */
export const RankBadge: React.FC<{ rank: number }> = ({ rank }) => {
  const isDarkMode = useTheme();

  const medal: Record<number, string> = {
    1: 'bg-yellow-400 text-yellow-900',
    2: 'bg-gray-300 text-gray-700',
    3: 'bg-amber-600 text-white',
  };

  const style =
    medal[rank] ?? (isDarkMode ? 'bg-gray-700 text-gray-300' : 'bg-gray-100 text-gray-500');

  return (
    <span
      className={`inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold ${style}`}
    >
      {rank}
    </span>
  );
};

export const Pill: React.FC<{
  children: React.ReactNode;
  tone?: 'success' | 'danger' | 'warning' | 'neutral' | 'info';
  className?: string;
}> = ({ children, tone = 'neutral', className = '' }) => {
  const tones: Record<string, string> = {
    success: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    danger: 'bg-red-500 text-white dark:bg-red-500/90',
    warning: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    info: 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    neutral: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
  };

  return (
    <span
      className={`inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full ${tones[tone]} ${className}`}
    >
      {children}
    </span>
  );
};

// ── Buttons and inputs ─────────────────────────────────────────────────

type ButtonVariant = 'primary' | 'outline' | 'subtle' | 'danger';

export const Button: React.FC<{
  children?: React.ReactNode;
  onClick?: () => void;
  variant?: ButtonVariant;
  icon?: React.ReactNode;
  disabled?: boolean;
  title?: string;
  className?: string;
  type?: 'button' | 'submit';
}> = ({ children, onClick, variant = 'outline', icon, disabled, title, className = '', type = 'button' }) => {
  const isDarkMode = useTheme();

  const variants: Record<ButtonVariant, string> = {
    primary: 'bg-blue-600 hover:bg-blue-700 text-white border-blue-600',
    danger: 'bg-red-600 hover:bg-red-700 text-white border-red-600',
    outline: isDarkMode
      ? 'bg-transparent border-gray-700 text-gray-200 hover:bg-gray-800'
      : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
    subtle: isDarkMode
      ? 'bg-transparent border-transparent text-blue-300 hover:bg-gray-800'
      : 'bg-transparent border-transparent text-blue-600 hover:bg-blue-50',
  };

  return (
    <button
      type={type}
      title={title}
      onClick={onClick}
      disabled={disabled}
      className={`inline-flex items-center justify-center gap-1.5 border rounded-lg px-3 py-1.5 text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${variants[variant]} ${className}`}
    >
      {icon}
      {children}
    </button>
  );
};

/** Shared class for date inputs, selects and text inputs on these pages. */
export const useControlClass = (): string => {
  const isDarkMode = useTheme();

  return `text-sm rounded-lg px-3 py-1.5 border outline-none focus:ring-2 focus:ring-blue-500/40 ${
    isDarkMode
      ? 'bg-gray-900 border-gray-700 text-gray-200 placeholder-gray-500'
      : 'bg-white border-gray-300 text-gray-800 placeholder-gray-400'
  }`;
};
