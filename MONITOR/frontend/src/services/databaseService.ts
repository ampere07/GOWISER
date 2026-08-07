import api from '../config/api';
import { requestCache } from '../utils/requestCache';
import { reportingService } from './reportingService';
import { monitorService } from './monitorService';
import {
  ConnectionFormValues,
  ConnectionTestResult,
  DatabaseConnection,
  DatabaseListResponse,
  IntrospectResult,
  SchemaMapping,
} from '../types/databases';

/**
 * The Databases configuration page.
 *
 * Deliberately uncached, unlike every other service here. This is the one screen
 * that writes, and an operator who has just saved a credential must see the saved
 * state — not a copy from ten seconds ago.
 *
 * Every write clears the reporting and source caches, because changing a
 * connection changes what every other page will report.
 */

/**
 * A connection change invalidates the whole portal's view of its data: the source
 * list, the capability map, and every section payload keyed by source.
 */
const invalidateDownstream = () => {
  reportingService.invalidate();
  monitorService.invalidate();
  requestCache.invalidate('reporting_capabilities');
  requestCache.invalidate('monitor_sources');
  requestCache.invalidatePrefix('reporting_');
  requestCache.invalidatePrefix('monitor_');
};

interface SaveResult {
  connection: DatabaseConnection;
  test: ConnectionTestResult;
}

export const databaseService = {
  list: async (): Promise<DatabaseListResponse> => {
    const response = await api.get<{ status: string; data: DatabaseListResponse }>('/databases');

    return response.data.data;
  },

  create: async (values: ConnectionFormValues): Promise<SaveResult> => {
    const response = await api.post<{ status: string; data: SaveResult }>('/databases', values);

    invalidateDownstream();

    return response.data.data;
  },

  update: async (id: number, values: ConnectionFormValues): Promise<SaveResult> => {
    const response = await api.put<{ status: string; data: SaveResult }>(`/databases/${id}`, values);

    invalidateDownstream();

    return response.data.data;
  },

  remove: async (id: number): Promise<string> => {
    const response = await api.delete<{ status: string; message: string }>(`/databases/${id}`);

    invalidateDownstream();

    return response.data.message;
  },

  /**
   * Re-checks connectivity without changing anything.
   *
   * Given a long timeout: an unreachable host fails on the TCP timeout, not
   * instantly, and cutting it off early would report a working database as down.
   */
  test: async (id: number): Promise<SaveResult> => {
    const response = await api.post<{ status: string; data: SaveResult }>(
      `/databases/${id}/test`,
      {},
      { timeout: 30000 }
    );

    return response.data.data;
  },

  /** Tables the connection can see, or the columns of one table. */
  introspect: async (id: number, table?: string): Promise<IntrospectResult> => {
    const response = await api.get<{ status: string; data: IntrospectResult }>(
      `/databases/${id}/introspect`,
      { params: table ? { table } : undefined, timeout: 30000 }
    );

    return response.data.data;
  },

  /**
   * What the reporting drivers expect from this database, against what is there.
   *
   * Uncached on both sides, like everything else on this page — the one moment
   * anybody opens a schema map is straight after changing something, which is
   * exactly when a cached copy is guaranteed wrong. The backend passes
   * `fresh: true` through to SchemaMap for the same reason.
   */
  mapping: async (id: number): Promise<SchemaMapping> => {
    const response = await api.get<{ status: string; data: SchemaMapping }>(
      `/databases/${id}/mapping`,
      { timeout: 30000 }
    );

    return response.data.data;
  },

  /** Persists the display order. The first enabled row is the portal's default. */
  reorder: async (order: number[]): Promise<void> => {
    await api.post('/databases/reorder', { order });

    invalidateDownstream();
  },
};
