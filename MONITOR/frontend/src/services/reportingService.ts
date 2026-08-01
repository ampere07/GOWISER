import api from '../config/api';
import { requestCache } from '../utils/requestCache';
import {
  EmployeeData,
  FinancialData,
  OperationsData,
  PrintableData,
  ReportingBranch,
  ReportingCapabilities,
  ReportingFilters,
  ReportingResponse,
  ReportingSection,
  SubscriberAnalyticsData,
  TechData,
} from '../types/reporting';

/**
 * Every read for the five reporting sections.
 *
 * Short client-side caching on top of the server's own, for the same reason
 * monitorService does it: several panels on one screen ask for the same payload
 * in the same tick, and that should be one HTTP request.
 */
const CACHE_MS = 10000;

/** URL path per section. */
const PATHS: Record<ReportingSection, string> = {
  subscriber_analytics: '/reporting/subscriber-analytics',
  financial: '/reporting/financial',
  operations: '/reporting/operations',
  tech: '/reporting/tech',
  employee: '/reporting/employee',
};

/** Drops empty values so the cache key and the URL stay stable. */
const clean = (params: Record<string, unknown>): Record<string, unknown> =>
  Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== null && value !== undefined && value !== '')
  );

/**
 * Builds the query for a section.
 *
 * Every section is sent the whole filter set even though each ignores most of
 * it. The alternative — a per-section whitelist here — is one more place to
 * forget a parameter, and the backend already validates and ignores what a
 * section does not use.
 */
const query = (source: string, filters: ReportingFilters): Record<string, unknown> =>
  clean({
    // The filter wins over the app-wide selection: this control is the one the
    // user is looking at on the page.
    source: filters.database || source,
    date_from: filters.dateFrom,
    date_to: filters.dateTo,
    branch: filters.branch ?? 'all',
    period: filters.period,
    branch_period: filters.branchPeriod,
    branch_year: filters.branchYear,
    overdue_search: filters.overdueSearch,
    overdue_plan_id: filters.overduePlanId || '',
    overdue_bucket: filters.overdueBucket,
    overdue_page: filters.overduePage,
  });

const fetchSection = async <T,>(
  section: ReportingSection,
  source: string,
  filters: ReportingFilters
): Promise<ReportingResponse<T>> => {
  const params = query(source, filters);

  return requestCache.get(
    `reporting_${section}_${JSON.stringify(params)}`,
    async () => {
      const response = await api.get<ReportingResponse<T>>(PATHS[section], { params });
      return response.data;
    },
    CACHE_MS
  );
};

export const reportingService = {
  /**
   * Which sections each source can serve, and which sources can serve each
   * section. The second half matters because the sections do not all live in one
   * database — the money is in NETMANAGER, the technicians in GOWISER.
   */
  getCapabilities: async (): Promise<ReportingCapabilities> =>
    requestCache.get(
      'reporting_capabilities',
      async () => {
        const response = await api.get<{ status: string; data: ReportingCapabilities }>(
          '/reporting/capabilities'
        );
        return response.data.data;
      },
      60000
    ),

  getBranches: async (source: string): Promise<ReportingBranch[]> =>
    requestCache.get(
      `reporting_branches_${source}`,
      async () => {
        const response = await api.get<ReportingResponse<{ branches: ReportingBranch[] }>>(
          '/reporting/branches',
          { params: { source } }
        );
        return response.data.data.branches;
      },
      300000
    ),

  getSubscriberAnalytics: (source: string, filters: ReportingFilters) =>
    fetchSection<SubscriberAnalyticsData>('subscriber_analytics', source, filters),

  getFinancial: (source: string, filters: ReportingFilters) =>
    fetchSection<FinancialData>('financial', source, filters),

  getOperations: (source: string, filters: ReportingFilters) =>
    fetchSection<OperationsData>('operations', source, filters),

  getTech: (source: string, filters: ReportingFilters) =>
    fetchSection<TechData>('tech', source, filters),

  getEmployee: (source: string, filters: ReportingFilters) =>
    fetchSection<EmployeeData>('employee', source, filters),

  /**
   * Line-level data for the print layouts.
   *
   * Uncached on both sides: a printed report is a record someone signs, so it
   * must reflect the ledger at the moment it was printed. The range may cover a
   * year of transactions, hence the raised timeout.
   */
  getPrintable: async (
    source: string,
    dateFrom: string,
    dateTo: string,
    branch: string | null
  ): Promise<ReportingResponse<PrintableData>> => {
    const response = await api.get<ReportingResponse<PrintableData>>('/reporting/printable', {
      params: clean({ source, date_from: dateFrom, date_to: dateTo, branch: branch ?? 'all' }),
      timeout: 120000,
    });

    return response.data;
  },

  /** Called by the manual refresh button so a poll cycle is not waited on. */
  invalidate: (source?: string) => {
    if (!source) {
      requestCache.invalidate('reporting_capabilities');
      return;
    }

    // Section keys carry their filters, so clear by prefix.
    (Object.keys(PATHS) as ReportingSection[]).forEach((section) =>
      requestCache.invalidatePrefix(`reporting_${section}`)
    );
  },
};
