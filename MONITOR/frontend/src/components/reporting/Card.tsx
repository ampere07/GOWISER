import React from 'react';
import { useTheme } from '../../hooks/useTheme';

interface CardProps {
  children: React.ReactNode;
  className?: string;
  /** Removes the inner padding, for cards whose body is a full-bleed table. */
  flush?: boolean;
}

/**
 * The surface every reporting panel sits on: a raised white card in light mode,
 * a lifted slate one in dark.
 *
 * Distinct from components/common/Panel, which is transparent with a hairline
 * border and suits the executive pages. The reference design for these modules
 * is card-based, and mixing the two on one screen reads as two half-finished
 * designs rather than one.
 */
export const Card: React.FC<CardProps> = ({ children, className = '', flush }) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`rounded-xl border overflow-hidden ${
        isDarkMode
          ? 'bg-gray-900 border-gray-800 shadow-none'
          : 'bg-white border-gray-200 shadow-sm'
      } ${flush ? '' : 'p-4 sm:p-5'} ${className}`}
    >
      {children}
    </div>
  );
};

interface CardHeaderProps {
  title: React.ReactNode;
  /** Pill shown beside the title — the period the numbers cover. */
  badge?: React.ReactNode;
  subtitle?: React.ReactNode;
  /** Controls aligned to the right: period tabs, expand links, filters. */
  actions?: React.ReactNode;
  icon?: React.ReactNode;
}

export const CardHeader: React.FC<CardHeaderProps> = ({ title, badge, subtitle, actions, icon }) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-3.5 border-b ${
        isDarkMode ? 'border-gray-800' : 'border-gray-200'
      }`}
    >
      <div className="flex items-center gap-2.5 min-w-0">
        {icon && <span className="flex-shrink-0 text-blue-500">{icon}</span>}
        <div className="min-w-0">
          <h3 className={`font-bold text-base truncate ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
            {title}
          </h3>
          {subtitle && (
            <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{subtitle}</p>
          )}
        </div>
        {badge && (
          <span
            className={`flex-shrink-0 text-xs font-medium px-2.5 py-1 rounded-md ${
              isDarkMode ? 'bg-blue-500/15 text-blue-300' : 'bg-blue-50 text-blue-700'
            }`}
          >
            {badge}
          </span>
        )}
      </div>

      {actions && <div className="flex items-center gap-2 flex-shrink-0">{actions}</div>}
    </div>
  );
};

/**
 * Body of a card that has a header. Kept separate from Card so a card can hold a
 * flush table under a padded header.
 */
export const CardBody: React.FC<{ children: React.ReactNode; className?: string }> = ({
  children,
  className = '',
}) => <div className={`p-4 sm:p-5 ${className}`}>{children}</div>;

export default Card;
