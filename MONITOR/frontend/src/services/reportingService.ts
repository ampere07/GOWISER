import api from '../config/api';
import { requestCache } from '../utils/requestCache';
import {
  EmployeeData,
  ExecutiveOverviewData,
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
  WidgetQuery,
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
 * Builds the query for a section, from the page filters plus the calling
 * widget's own date range.
 *
 * The range comes from the widget rather than the page because the page-level
 * period filter is gone — see useWidgetRange for why. Everything else is still
 * page-wide, and every section is sent the whole set even though each ignores
 * most of it: a per-section whitelist here would be one more place to forget a
 * parameter, and the backend already validates and ignores what a section does
 * not use.
 */
const query = (
  source: string,
  filters: ReportingFilters,
  widget?: WidgetQuery
): Record<string, unknown> =>
  clean({
    // The filter wins over the app-wide selection: this control is the one the
    // user is looking at on the page.
    source: filters.database || source,
    date_from: widget?.dateFrom,
    date_to: widget?.dateTo,
    branch: filters.branch ?? 'all',
    period: widget?.period,
    branch_period: filters.branchPeriod,
    branch_year: filters.branchYear,
    overdue_search: filters.overdueSearch,
    overdue_plan_id: filters.overduePlanId || '',
    overdue_bucket: filters.overdueBucket,
    overdue_page: filters.overduePage,
  });

/**
 * One section payload for one widget's window.
 *
 * The cache key is the full parameter set, which is what makes per-widget ranges
 * affordable: every widget still on the page's default range produces an
 * identical key and collapses into a single HTTP request. Only a widget someone
 * has actually moved issues one of its own — and the server caches per parameter
 * bucket too, so even that is usually free on the second viewer.
 */
const fetchSection = async <T,>(
  section: ReportingSection,
  source: string,
  filters: ReportingFilters,
  widget?: WidgetQuery
): Promise<ReportingResponse<T>> => {
  const params = query(source, filters, widget);

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

  getSubscriberAnalytics: (source: string, filters: ReportingFilters, widget?: WidgetQuery) =>
    fetchSection<SubscriberAnalyticsData>('subscriber_analytics', source, filters, widget),

  getFinancial: (source: string, filters: ReportingFilters, widget?: WidgetQuery) =>
    fetchSection<FinancialData>('financial', source, filters, widget),

  getOperations: (source: string, filters: ReportingFilters, widget?: WidgetQuery) =>
    fetchSection<OperationsData>('operations', source, filters, widget),

  getTech: (source: string, filters: ReportingFilters, widget?: WidgetQuery) =>
    fetchSection<TechData>('tech', source, filters, widget),

  getEmployee: (source: string, filters: ReportingFilters, widget?: WidgetQuery) =>
    fetchSection<EmployeeData>('employee', source, filters, widget),

  /**
   * Module 5 — the consolidated C-suite summary.
   *
   * Not a section: composed server-side from several of them, so it has no
   * source parameter. The role check that guards it lives in the controller, and
   * a 403 here means the sidebar and the backend disagree — which the caller
   * surfaces rather than swallows.
   */
  getExecutiveOverview: async (widget?: WidgetQuery): Promise<ExecutiveOverviewData> =>
    requestCache.get(
      `reporting_executive_${JSON.stringify(widget ?? {})}`,
      async () => {
        const response = await api.get<{ status: string; data: ExecutiveOverviewData }>(
          '/executive/overview',
          { params: clean({ date_from: widget?.dateFrom, date_to: widget?.dateTo }) }
        );

        return response.data.data;
      },
      CACHE_MS
    ),

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

    // Section keys carry their filters and their widget's range, so clear by
    // prefix — there is no single key to drop any more.
    (Object.keys(PATHS) as ReportingSection[]).forEach((section) =>
      requestCache.invalidatePrefix(`reporting_${section}`)
    );

    requestCache.invalidatePrefix('reporting_executive');
  },
};
