import React, { useEffect, useState } from 'react';
import { Filter, X } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { ReportingBranch, ReportingFilters } from '../../types/reporting';
import Card from './Card';
import { DatabaseFilter } from './DatabaseFilter';
import { Button, useControlClass } from './primitives';

interface FilterBarProps {
  filters: ReportingFilters;
  onChange: (next: Partial<ReportingFilters>) => void;
  onReset: () => void;
  branches: ReportingBranch[];
  /** Databases that can serve this section. Hidden when fewer than two. */
  databases?: { key: string; label: string }[];
  /** Hidden on sections with no date dimension. */
  showRange?: boolean;
  /** Hidden on sources with no branch concept — GOWISER has none. */
  showBranch?: boolean;
  children?: React.ReactNode;
}

/**
 * The date range and branch controls shared by the five sections.
 *
 * The range is staged in two inputs and only applied on the filter button, which
 * mirrors the source system. That is not only fidelity: a half-typed date input
 * fires change events, so applying live would send requests for ranges like
 * 0002-01-01 while someone types a year.
 */
const FilterBar: React.FC<FilterBarProps> = ({
  filters,
  onChange,
  onReset,
  branches,
  databases = [],
  showRange = true,
  showBranch = true,
  children,
}) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [draftFrom, setDraftFrom] = useState(filters.dateFrom);
  const [draftTo, setDraftTo] = useState(filters.dateTo);

  // Re-sync when the applied range changes from elsewhere — a reset, or a source
  // switch that rebuilt the filters.
  useEffect(() => {
    setDraftFrom(filters.dateFrom);
    setDraftTo(filters.dateTo);
  }, [filters.dateFrom, filters.dateTo]);

  const apply = () => {
    // A reversed range is swapped rather than rejected. The backend does the
    // same, and an error for an obvious slip is worse than just fixing it.
    const [from, to] = draftFrom > draftTo ? [draftTo, draftFrom] : [draftFrom, draftTo];

    setDraftFrom(from);
    setDraftTo(to);
    onChange({ dateFrom: from, dateTo: to, overduePage: 1 });
  };

  const dirty = draftFrom !== filters.dateFrom || draftTo !== filters.dateTo;

  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2">
        {/* First in the bar because it is the widest-reaching control: it decides
            what the rest of the filters even apply to. */}
        <DatabaseFilter
          value={filters.database}
          onChange={(database) => onChange({ database })}
          options={databases}
        />

        {showRange && (
          <>
            <span className={`text-sm font-medium ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
              Period:
            </span>

            <input
              type="date"
              value={draftFrom}
              max={draftTo || undefined}
              onChange={(event) => setDraftFrom(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') apply();
              }}
              className={controlClass}
              aria-label="From date"
            />
            <input
              type="date"
              value={draftTo}
              min={draftFrom || undefined}
              onChange={(event) => setDraftTo(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') apply();
              }}
              className={controlClass}
              aria-label="To date"
            />

            <Button
              variant="primary"
              icon={<Filter size={14} />}
              onClick={apply}
              title="Apply this date range"
              className={dirty ? 'ring-2 ring-blue-500/40' : ''}
            />
            <Button
              variant="outline"
              icon={<X size={14} />}
              onClick={onReset}
              title="Reset filters to month-to-date"
            />
          </>
        )}

        {children}

        {showBranch && branches.length > 0 && (
          <select
            value={filters.branch ?? 'all'}
            onChange={(event) =>
              onChange({
                branch: event.target.value === 'all' ? null : event.target.value,
                overduePage: 1,
              })
            }
            className={`${controlClass} ml-auto`}
            aria-label="Filter by branch"
          >
            <option value="all">All branches</option>
            {branches.map((branch) => (
              <option key={branch.id} value={branch.id}>
                {branch.label}
              </option>
            ))}
          </select>
        )}
      </div>
    </Card>
  );
};

export default FilterBar;
