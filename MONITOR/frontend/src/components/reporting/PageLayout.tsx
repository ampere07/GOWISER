import React from 'react';
import { useTheme } from '../../hooks/useTheme';

interface ReportingPageProps {
  children: React.ReactNode;
}

/**
 * Page frame for the reporting modules.
 *
 * Wider and denser than components/common/PageShell, which caps at max-w-7xl for
 * the executive pages. These screens carry eight-column tables beside charts, and
 * that cap forces horizontal scrolling on exactly the desktop monitors they are
 * read on.
 */
export const ReportingPage: React.FC<ReportingPageProps> = ({ children }) => {
  const isDarkMode = useTheme();

  return (
    <div className={`min-h-full ${isDarkMode ? 'bg-gray-950 text-white' : 'bg-gray-50 text-gray-900'}`}>
      <div className="p-4 sm:p-6 max-w-[1600px] mx-auto space-y-5">{children}</div>
    </div>
  );
};

interface PageHeaderProps {
  title: string;
  subtitle?: React.ReactNode;
  actions?: React.ReactNode;
}

export const PageHeader: React.FC<PageHeaderProps> = ({ title, subtitle, actions }) => {
  const isDarkMode = useTheme();

  return (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <h1 className="text-2xl sm:text-3xl font-bold tracking-tight">{title}</h1>
        {subtitle && (
          <p className={`text-sm mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{subtitle}</p>
        )}
      </div>

      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
};

export default ReportingPage;
