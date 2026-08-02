import React, { useState } from 'react';
import { Info } from 'lucide-react';

interface MetricTooltipProps {
  formula?: string;
  explanation: string;
  title?: string;
}

export const MetricTooltip: React.FC<MetricTooltipProps> = ({ formula, explanation, title }) => {
  const [isVisible, setIsVisible] = useState(false);

  return (
    <div className="relative inline-flex items-center ml-1.5 z-20">
      <button
        type="button"
        className="text-slate-400 hover:text-indigo-600 focus:outline-none transition-colors p-0.5 rounded-full hover:bg-slate-100 dark:hover:bg-slate-800"
        onMouseEnter={() => setIsVisible(true)}
        onMouseLeave={() => setIsVisible(false)}
        onClick={() => setIsVisible(!isVisible)}
        aria-label="View Calculation Formula"
      >
        <Info className="w-3.5 h-3.5" />
      </button>

      {isVisible && (
        <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-64 p-3 bg-slate-900 text-white text-xs rounded-lg shadow-xl border border-slate-700 pointer-events-none transition-all duration-150 animate-in fade-in zoom-in-95">
          {title && <div className="font-semibold text-indigo-300 mb-1 border-b border-slate-700 pb-1">{title}</div>}
          <div className="text-slate-200 leading-relaxed mb-1.5">{explanation}</div>
          {formula && (
            <div className="bg-slate-950 p-1.5 rounded font-mono text-[11px] text-emerald-400 border border-slate-800 break-words">
              <span className="text-slate-500 select-none">Formula: </span>
              {formula}
            </div>
          )}
          <div className="absolute top-full left-1/2 -translate-x-1/2 -mt-1 border-4 border-transparent border-t-slate-900" />
        </div>
      )}
    </div>
  );
};
