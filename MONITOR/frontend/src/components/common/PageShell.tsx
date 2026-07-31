import React from 'react';
import { AlertTriangle } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';

interface PageShellProps {
  title: string;
  subtitle?: string;
  error?: string | null;
  children: React.ReactNode;
}

/**
 * Common page frame: heading, error banner, background glows. Every dashboard
 * page uses it so they stay visually identical as pages get added.
 */
const PageShell: React.FC<PageShellProps> = ({ title, subtitle, error, children }) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`min-h-screen transition-colors duration-300 ${
        isDarkMode ? 'bg-slate-950 text-white' : 'bg-slate-50 text-slate-900'
      }`}
    >
      <div className="p-4 sm:p-6 md:p-8 max-w-7xl mx-auto space-y-6 md:space-y-8">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold tracking-tight">{title}</h1>
          {subtitle && (
            <p className={`text-sm mt-1 ${isDarkMode ? 'text-slate-400' : 'text-slate-500'}`}>{subtitle}</p>
          )}
        </div>

        {error && (
          <div className="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 flex items-center gap-3">
            <AlertTriangle className="h-4 w-4 flex-shrink-0" />
            <p className="font-medium text-sm">{error}</p>
          </div>
        )}

        {children}

        {isDarkMode && (
          <>
            <div className="fixed top-0 -left-64 w-[600px] h-[600px] bg-blue-600/10 rounded-full blur-[120px] pointer-events-none -z-10" />
            <div className="fixed bottom-0 -right-64 w-[600px] h-[600px] bg-indigo-600/10 rounded-full blur-[120px] pointer-events-none -z-10" />
          </>
        )}
      </div>
    </div>
  );
};

export default PageShell;
