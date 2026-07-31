import React from 'react';
import { useTheme } from '../../hooks/useTheme';

interface PanelProps {
  title: string;
  /** Period or filter the numbers cover — always state it. */
  scope?: string;
  children: React.ReactNode;
  className?: string;
}

const Panel: React.FC<PanelProps> = ({ title, scope, children, className = '' }) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`rounded-2xl border p-6 ${
        isDarkMode ? 'bg-transparent border-gray-700' : 'bg-transparent border-gray-400'
      } ${className}`}
    >
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between mb-5 gap-1 sm:gap-4">
        <h3
          className={`font-bold uppercase tracking-widest text-xs ${
            isDarkMode ? 'text-slate-100' : 'text-slate-800'
          }`}
        >
          {title}
        </h3>
        {scope && (
          <span
            className={`text-[10px] tracking-wider ${isDarkMode ? 'text-slate-400' : 'text-slate-500'}`}
          >
            {scope}
          </span>
        )}
      </div>

      {children}
    </div>
  );
};

export default Panel;
