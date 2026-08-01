import React, { useCallback, useEffect, useState } from 'react';
import { Info } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { useMonitorStore } from '../../store/monitorStore';
import { reportingService } from '../../services/reportingService';
import {
  ALL_DATABASES,
  ReportingBranch,
  ReportingFilters,
  ReportingSection,
} from '../../types/reporting';
import { monthStartIso, todayIso } from '../../utils/format';

/** Month-to-date, as the source system defaults to. */
export const defaultFilters = (): ReportingFilters => ({
  // Defaults to every database. With one configured this is indistinguishable
  // from picking it; with eight it is the figure someone actually wants first.
  database: ALL_DATABASES,
  dateFrom: monthStartIso(),
  dateTo: todayIso(),
  branch: null,
  period: 'monthly',
  branchPeriod: 'monthly',
  branchYear: new Date().getFullYear(),
  overdueSearch: '',
  overduePlanId: 0,
  overdueBucket: '',
  overduePage: 1,
});

/**
 * Filter state plus the branch list, shared by the five sections.
 *
 * Everything here is source-specific — branch ids, plan ids, page numbers — so a
 * source switch rebuilds the whole set rather than carrying stale ids into a
 * database where they mean something else, or nothing.
 */
export const useSectionFilters = (section: ReportingSection) => {
  const activeSource = useMonitorStore((state) => state.activeSource);

  const [filters, setFilters] = useState<ReportingFilters>(defaultFilters);
  const [branches, setBranches] = useState<ReportingBranch[]>([]);
  const [databases, setDatabases] = useState<{ key: string; label: string }[]>([]);

  // Which databases can answer *this* section. Not every one can: only GOWISER
  // holds technicians, so the Tech filter must not offer a NetManager database.
  useEffect(() => {
    let cancelled = false;

    reportingService
      .getCapabilities()
      .then((result) => {
        if (!cancelled) setDatabases(result.sections?.[section] ?? []);
      })
      .catch((err) => console.error('Failed to load reporting capabilities:', err));

    return () => {
      cancelled = true;
    };
  }, [section]);

  // Branch ids belong to one database, so there is no branch filter in
  // aggregate mode — the backend returns an empty list and the control hides.
  useEffect(() => {
    setBranches([]);

    const source = filters.database === ALL_DATABASES ? activeSource : filters.database;

    if (!source || filters.database === ALL_DATABASES) return;

    let cancelled = false;

    reportingService
      .getBranches(source)
      .then((result) => {
        if (!cancelled) setBranches(result);
      })
      .catch((err) => console.error('Failed to load branches:', err));

    return () => {
      cancelled = true;
    };
  }, [activeSource, filters.database]);

  const update = useCallback(
    (next: Partial<ReportingFilters>) =>
      setFilters((current) => {
        // Changing database invalidates the branch and the ledger page: a branch
        // id from one database is meaningless in another, and page 4 of the old
        // result is not page 4 of the new one.
        const switched = next.database !== undefined && next.database !== current.database;

        return {
          ...current,
          ...next,
          ...(switched ? { branch: null, overduePlanId: 0, overduePage: 1 } : {}),
        };
      }),
    []
  );

  // Reset keeps the chosen database: it is a scope, not a filter someone is
  // trying to clear, and silently switching it back would move the ground under
  // them.
  const reset = useCallback(
    () => setFilters((current) => ({ ...defaultFilters(), database: current.database })),
    []
  );

  return { filters, update, reset, branches, databases };
};

/**
 * Says so when a section was answered by a different system than the one
 * selected.
 *
 * The sections do not all live in one database: the money is in NETMANAGER and
 * the technicians are in GOWISER. Falling through is the right behaviour, but a
 * silent fallthrough means someone reads GOWISER's figures believing they are
 * NETMANAGER's — so it is stated on the page, every time.
 */
export const SourceNotice: React.FC<{ show: boolean; sourceLabel: string }> = ({
  show,
  sourceLabel,
}) => {
  const isDarkMode = useTheme();

  if (!show) return null;

  return (
    <div
      className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
        isDarkMode
          ? 'border-blue-800/60 bg-blue-500/10 text-blue-200'
          : 'border-blue-200 bg-blue-50 text-blue-800'
      }`}
    >
      <Info size={15} className="mt-0.5 flex-shrink-0" />
      <span>
        The system you have selected does not hold this data, so these figures come from{' '}
        <strong>{sourceLabel}</strong>.
      </span>
    </div>
  );
};
