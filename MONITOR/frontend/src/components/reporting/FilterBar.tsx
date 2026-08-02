import React from 'react';
import { X } from 'lucide-react';
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
  /** Hidden on sources with no branch concept — GOWISER has none. */
  showBranch?: boolean;
  children?: React.ReactNode;
}

/**
 * The page-wide scope controls: which database, and which branch.
 *
 * The date range used to live here and no longer does. One period filter over a
 * whole page meant every panel moved together, which made the comparison these
 * screens exist for — this month's collections against the twelve-month trend —
 * impossible to draw. Each widget now carries its own range control in its
 * header; see WidgetRange and useWidgetRange.
 *
 * What is left is genuinely page-wide. A database is a scope, not a filter, and a
 * branch id means the same thing to every panel on the screen.
 *
 * The bar hides itself entirely when neither control has anything to offer — one
 * database and no branches — rather than rendering an empty card.
 */
const FilterBar: React.FC<FilterBarProps> = ({
  filters,
  onChange,
  onReset,
  branches,
  databases = [],
  showBranch = true,
  children,
}) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const hasDatabasePicker = databases.length > 1;
  const hasBranchPicker = showBranch && branches.length > 0;

  if (!hasDatabasePicker && !hasBranchPicker && !children) {
    return null;
  }

  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2">
        <span className={`text-sm font-medium ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
          Scope:
        </span>

        {/* First in the bar because it is the widest-reaching control: it
            decides what the rest of the page even applies to. */}
        <DatabaseFilter
          value={filters.database}
          onChange={(database) => onChange({ database })}
          options={databases}
        />

        {hasBranchPicker && (
          <select
            value={filters.branch ?? 'all'}
            onChange={(event) =>
              onChange({
                branch: event.target.value === 'all' ? null : event.target.value,
                // A branch change alters how many pages the ledger has, so
                // page 4 of the old result is meaningless against the new one.
                overduePage: 1,
              })
            }
            className={controlClass}
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

        {children}

        <Button
          variant="outline"
          icon={<X size={14} />}
          onClick={onReset}
          title="Reset the page scope"
          className="ml-auto"
        />
      </div>

      <p className={`mt-2 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Each widget below carries its own date range. Set them independently to
        compare one period against another on the same screen.
      </p>
    </Card>
  );
};

export default FilterBar;
