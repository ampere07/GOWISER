import React from 'react';
import { useTheme } from '../../hooks/useTheme';
import Card from './Card';

/** Semantic tone, not a colour name — so a change of palette stays coherent. */
export type Tone = 'success' | 'danger' | 'warning' | 'neutral' | 'info';

const VALUE_TONE: Record<Tone, string> = {
  success: 'text-emerald-600 dark:text-emerald-400',
  danger: 'text-red-600 dark:text-red-400',
  warning: 'text-amber-500 dark:text-amber-400',
  neutral: 'text-gray-500 dark:text-gray-400',
  info: 'text-blue-600 dark:text-blue-400',
};

const CHIP_TONE: Record<Tone, string> = {
  success: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
  danger: 'bg-red-100 text-red-500 dark:bg-red-500/15 dark:text-red-400',
  warning: 'bg-amber-100 text-amber-500 dark:bg-amber-500/15 dark:text-amber-400',
  neutral: 'bg-gray-100 text-gray-500 dark:bg-gray-700/60 dark:text-gray-300',
  info: 'bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
};

export const ACCENT_TONE: Record<Tone, string> = {
  success: '#198754',
  danger: '#dc3545',
  warning: '#fd7e14',
  neutral: '#6c757d',
  info: '#0d6efd',
};

interface StatCardProps {
  label: string;
  value: React.ReactNode;
  tone?: Tone;
  icon?: React.ReactNode;
  /** Small line under the number: a total, a scope, a caveat. */
  caption?: React.ReactNode;
  loading?: boolean;
}

/**
 * The four headline counters at the top of the Dashboard: large coloured value,
 * tinted icon chip on the right, one line of context underneath.
 */
export const StatCard: React.FC<StatCardProps> = ({
  label,
  value,
  tone = 'neutral',
  icon,
  caption,
  loading,
}) => {
  const isDarkMode = useTheme();

  return (
    <Card>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className={`text-sm mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{label}</p>
          <p className={`text-3xl font-bold tracking-tight truncate ${VALUE_TONE[tone]}`}>
            {loading ? <span className={isDarkMode ? 'text-gray-600' : 'text-gray-300'}>—</span> : value}
          </p>
        </div>

        {icon && (
          <span
            className={`flex-shrink-0 w-11 h-11 rounded-xl flex items-center justify-center ${CHIP_TONE[tone]}`}
          >
            {icon}
          </span>
        )}
      </div>

      {caption && (
        <p className={`mt-2 text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{caption}</p>
      )}
    </Card>
  );
};

interface AccentCardProps {
  label: string;
  value: React.ReactNode;
  tone: Tone;
  caption?: React.ReactNode;
  icon?: React.ReactNode;
  /** Sub-panels under the value, e.g. the Office / Portal collection split. */
  children?: React.ReactNode;
  loading?: boolean;
}

/**
 * Income / Expenses / Net. Same information hierarchy as StatCard, but with a
 * thick left rule in the tone colour — which is how the reference distinguishes
 * money cards from counting cards at a glance.
 */
export const AccentCard: React.FC<AccentCardProps> = ({
  label,
  value,
  tone,
  caption,
  icon,
  children,
  loading,
}) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`rounded-xl border p-4 sm:p-5 h-full ${
        isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200 shadow-sm'
      }`}
      style={{ borderLeftWidth: '4px', borderLeftColor: ACCENT_TONE[tone] }}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className={`flex items-center gap-1.5 text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
            {icon}
            <span>{label}</span>
          </div>
          <p className={`mt-1.5 text-3xl font-bold tracking-tight truncate ${VALUE_TONE[tone]}`}>
            {loading ? <span className={isDarkMode ? 'text-gray-600' : 'text-gray-300'}>—</span> : value}
          </p>
          {caption && (
            <p className={`mt-1 text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>{caption}</p>
          )}
        </div>
      </div>

      {children && (
        <div className={`mt-4 pt-4 border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}>
          {children}
        </div>
      )}
    </div>
  );
};

/**
 * One of the small boxes inside an AccentCard — the Office / Portal split.
 */
export const MiniStat: React.FC<{
  label: string;
  value: React.ReactNode;
  caption?: React.ReactNode;
  tone?: Tone;
}> = ({ label, value, caption, tone = 'success' }) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`rounded-lg border px-3 py-2 ${
        isDarkMode ? 'border-gray-800 bg-gray-900/60' : 'border-gray-200 bg-white'
      }`}
    >
      <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{label}</p>
      <p className={`text-base font-bold ${VALUE_TONE[tone]}`}>{value}</p>
      {caption && (
        <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>{caption}</p>
      )}
    </div>
  );
};

export default StatCard;
