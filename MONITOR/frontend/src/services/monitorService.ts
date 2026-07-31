import api from '../config/api';
import { requestCache } from '../utils/requestCache';
import {
  Branch,
  Consolidated,
  Financials,
  FinancialPeriod,
  MonitorSource,
  Operations,
  Overview,
  Revenue,
  SourcedResponse,
} from '../types/monitor';

/**
 * Every dashboard read goes through here.
 *
 * Short client-side caching on top of the server's own cache: several panels
 * on one screen ask for the same source at the same moment, and there is no
 * reason for that to be more than one HTTP request.
 */
const CACHE_MS = 10000;

export const monitorService = {
  getSources: async (): Promise<{ sources: MonitorSource[]; default: string }> => {
    return requestCache.get(
      'monitor_sources',
      async () => {
        const response = await api.get<{ status: string; data: { sources: MonitorSource[]; default: string } }>(
          '/monitor/sources'
        );
        return response.data.data;
      },
      60000
    );
  },

  getOverview: async (source: string): Promise<SourcedResponse<Overview>> => {
    return requestCache.get(
      `monitor_overview_${source}`,
      async () => {
        const response = await api.get<SourcedResponse<Overview>>('/monitor/overview', {
          params: { source },
        });
        return response.data;
      },
      CACHE_MS
    );
  },

  getOperations: async (source: string): Promise<SourcedResponse<Operations>> => {
    return requestCache.get(
      `monitor_operations_${source}`,
      async () => {
        const response = await api.get<SourcedResponse<Operations>>('/monitor/operations', {
          params: { source },
        });
        return response.data;
      },
      CACHE_MS
    );
  },

  getRevenue: async (source: string, months = 12): Promise<SourcedResponse<Revenue>> => {
    return requestCache.get(
      `monitor_revenue_${source}_${months}`,
      async () => {
        const response = await api.get<SourcedResponse<Revenue>>('/monitor/revenue', {
          params: { source, months },
        });
        return response.data;
      },
      CACHE_MS
    );
  },

  getBranches: async (source: string): Promise<Branch[]> => {
    return requestCache.get(
      `monitor_branches_${source}`,
      async () => {
        const response = await api.get<SourcedResponse<{ branches: Branch[] }>>('/monitor/branches', {
          params: { source },
        });
        return response.data.data.branches;
      },
      300000
    );
  },

  getFinancials: async (
    source: string,
    period: FinancialPeriod,
    branch: string | null,
    asOf: string | null
  ): Promise<SourcedResponse<Financials>> => {
    return requestCache.get(
      `monitor_financials_${source}_${period}_${branch ?? 'all'}_${asOf ?? 'today'}`,
      async () => {
        const response = await api.get<SourcedResponse<Financials>>('/monitor/financials', {
          params: { source, period, branch: branch ?? 'all', date: asOf ?? undefined },
        });
        return response.data;
      },
      CACHE_MS
    );
  },

  getConsolidated: async (): Promise<Consolidated> => {
    return requestCache.get(
      'monitor_consolidated',
      async () => {
        const response = await api.get<{ status: string; data: Consolidated }>('/monitor/consolidated');
        return response.data.data;
      },
      CACHE_MS
    );
  },

  /** Called by the manual refresh button so a poll cycle isn't waited on. */
  invalidate: (source?: string) => {
    if (source) {
      requestCache.invalidate(`monitor_overview_${source}`);
      requestCache.invalidate(`monitor_operations_${source}`);
      requestCache.invalidate(`monitor_revenue_${source}_12`);
      // Financial keys carry period/branch/date, so clear by prefix.
      requestCache.invalidatePrefix(`monitor_financials_${source}`);
    }
    requestCache.invalidate('monitor_consolidated');
  },
};
