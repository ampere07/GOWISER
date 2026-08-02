import api from '../config/api';
import { requestCache } from '../utils/requestCache';
import { MonitorSource } from '../types/monitor';

/**
 * The source list: which databases exist, and which the signed-in role may read.
 *
 * All that survives of this service. It used to carry the executive rollup
 * readers too — overview, revenue, financials, consolidated — but those pages
 * were removed in favour of the reporting modules and the group overview, which
 * compose the same figures through one code path rather than a second that could
 * drift away from it.
 *
 * Cached for a minute: every reporting section needs the list before it can ask
 * anything, and it changes only when someone edits the Databases page — which
 * invalidates it explicitly rather than waiting the TTL out.
 */
export const monitorService = {
  getSources: async (): Promise<{ sources: MonitorSource[]; default: string | null }> =>
    requestCache.get(
      'monitor_sources',
      async () => {
        const response = await api.get<{
          status: string;
          data: { sources: MonitorSource[]; default: string | null };
        }>('/monitor/sources');

        return response.data.data;
      },
      60000
    ),

  /** Called by the manual refresh button so a poll cycle is not waited on. */
  invalidate: () => requestCache.invalidate('monitor_sources'),
};
