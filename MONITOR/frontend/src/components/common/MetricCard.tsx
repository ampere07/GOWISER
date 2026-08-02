import React from 'react';
import { useTheme } from '../../hooks/useTheme';
import { MetricTooltip } from './MetricTooltip';

interface MetricCardProps {
  title: string;
  value: number | string | undefined;
  icon?: React.ReactNode;
  iconColor?: string;
  /** Small line under the number: a comparison, a scope, a caveat. */
  caption?: string;
  loading?: boolean;
  /** Renders the value as PHP currency. */
  currency?: boolean;
  tooltipExplanation?: string;
  tooltipFormula?: string;
}

export const formatCurrency = (value: number): string =>
  new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 0,
  }).format(value);

const MetricCard: React.FC<MetricCardProps> = ({
  title,
  value,
  icon,
  iconColor = 'text-slate-500',
  caption,
  loading,
  currency,
  tooltipExplanation,
  tooltipFormula,
}) => {
  const isDarkMode = useTheme();

  const display = () => {
    if (loading && value === undefined) return '...';
    if (value === undefined || value === null) return currency ? formatCurrency(0) : '0';
    if (typeof value === 'string') return value;
    return currency ? formatCurrency(value) : value.toLocaleString();
  };

  return (
    <div
      className={`relative overflow-hidden rounded-xl border p-4 sm:p-6 transition-all duration-300 ${
        isDarkMode ? 'bg-transparent border-gray-700' : 'bg-transparent border-gray-400'
      }`}
    >
      <div className="flex items-center justify-between mb-2">
        <div className="flex items-center">
          <span
            className={`text-xs sm:text-sm font-semibold uppercase tracking-wider ${
              isDarkMode ? 'text-slate-400' : 'text-slate-500'
            }`}
          >
            {title}
          </span>
          {tooltipExplanation && (
            <MetricTooltip
              title={title}
              explanation={tooltipExplanation}
              formula={tooltipFormula}
            />
          )}
        </div>
        {icon && <div className={iconColor}>{icon}</div>}
      </div>

      <div className="flex items-baseline gap-2">
        <h3 className="text-2xl sm:text-3xl font-bold tracking-tight">{display()}</h3>
      </div>

      {caption && (
        <p className={`mt-1 text-[11px] ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}>{caption}</p>
      )}
    </div>
  );
};

export default MetricCard;
