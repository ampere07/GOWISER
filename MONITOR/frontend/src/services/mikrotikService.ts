import api from '../config/api';
import {
  GroupUpdateResult,
  MikrotikOverview,
  MikrotikUserList,
  RadiusConfigList,
  RadiusProbeResult,
  RadiusServerConfig,
  RadiusServerFormValues,
  RateLimitPreview,
  UserMoveResult,
} from '../types/mikrotik';

/**
 * MikroTik RADIUS reads and writes, plus the RADIUS API settings behind them.
 *
 * Deliberately uncached, unlike reportingService. Everything here describes the
 * live state of a router an operator is in the middle of changing, and serving a
 * ten-second-old group list to somebody who has just edited it is how they come
 * to press the button twice. The server caches reads briefly and flushes that
 * cache on every write, which is the right layer for it.
 */
export const mikrotikService = {
  /** Groups, profiles, limitations, live sessions and the queue, in one call. */
  async overview(): Promise<MikrotikOverview> {
    const { data } = await api.get('/mikrotik');

    return data.data;
  },

  /**
   * Users, filtered server-side.
   *
   * The search runs across username, group, caller ID and address before the
   * response is capped, so a match on row 4,000 is still found. Filtering in the
   * browser would only ever search the page it had already been sent.
   */
  async users(search?: string, limit?: number): Promise<MikrotikUserList> {
    const { data } = await api.get('/mikrotik/users', {
      params: { ...(search ? { search } : {}), ...(limit ? { limit } : {}) },
    });

    return data.data;
  },

  /**
   * What a typed rate limit resolves to, without applying it.
   *
   * Server-side on purpose. A second implementation of the kb/mb/gb conversion
   * in TypeScript would eventually drift from the one that actually writes to
   * the router, and the drift would show up as a subscriber on the wrong speed.
   */
  async previewRateLimit(rateLimit: string): Promise<RateLimitPreview> {
    const { data } = await api.post('/mikrotik/rate-limit/preview', { rate_limit: rateLimit });

    return data.data;
  },

  /**
   * Changes a group's rate limit and/or framed pool.
   *
   * Returns the before and after as the *router* reports them, not as they were
   * submitted — RouterOS normalises some values, and showing the request back as
   * if it were the result would let the screen disagree with the device.
   *
   * `live_sessions` is the blast radius: how many subscribers are still running
   * on the old settings and would need disconnecting for this to take effect.
   */
  async updateGroup(
    group: string,
    changes: { rate_limit?: string; framed_pool?: string; comment?: string }
  ): Promise<GroupUpdateResult> {
    const { data } = await api.put(`/mikrotik/groups/${encodeURIComponent(group)}`, changes);

    return data.data;
  },

  /** Moves one user into another group. */
  async moveUser(username: string, group: string): Promise<UserMoveResult> {
    const { data } = await api.put(
      `/mikrotik/users/${encodeURIComponent(username)}/group`,
      { group }
    );

    return data.data;
  },

  /** Disconnects matching sessions immediately. */
  async kickNow(target: { group?: string; usernames?: string[]; reason?: string }) {
    const { data } = await api.post('/mikrotik/kick/now', target);

    return data;
  },

  /**
   * Schedules the same disconnection for a wall-clock time in GMT+8.
   *
   * `at` is sent as plain "YYYY-MM-DD HH:MM" with no offset. The server reads it
   * in Asia/Manila — the browser's own timezone is deliberately not consulted,
   * because an operator working from another country is still scheduling work in
   * the field, and the field is in GMT+8.
   */
  async scheduleKick(target: {
    group?: string;
    usernames?: string[];
    reason?: string;
    at: string;
  }) {
    const { data } = await api.post('/mikrotik/kick/schedule', target);

    return data;
  },

  /** Queues the same disconnection for the next maintenance window. */
  async kickLater(target: { group?: string; usernames?: string[]; reason?: string }) {
    const { data } = await api.post('/mikrotik/kick/later', target);

    return data;
  },

  async cancelKick(id: number) {
    const { data } = await api.delete(`/mikrotik/kick/${id}`);

    return data;
  },
};

/**
 * RADIUS API settings — the endpoints the module above talks to.
 *
 * Ported from GOWISER's radius_config so the same two servers are described the
 * same way in both systems. Passwords travel one way only: they can be set and
 * never read back.
 */
export const radiusConfigService = {
  async list(): Promise<RadiusConfigList> {
    const { data } = await api.get('/radius-config');

    return data.data;
  },

  async create(values: RadiusServerFormValues): Promise<RadiusServerConfig> {
    const { data } = await api.post('/radius-config', values);

    return data.data;
  },

  /**
   * Updates one server.
   *
   * An omitted or blank `password` leaves the stored one alone — the form never
   * receives it, so it has nothing to send back, and treating a blank field as
   * "clear it" would wipe the credentials on every unrelated edit.
   */
  async update(id: number, values: Partial<RadiusServerFormValues>): Promise<RadiusServerConfig> {
    const { data } = await api.put(`/radius-config/${id}`, values);

    return data.data;
  },

  async remove(id: number): Promise<string> {
    const { data } = await api.delete(`/radius-config/${id}`);

    return data.message;
  },

  /** Authenticates against this server, rather than only opening a socket. */
  async test(id: number): Promise<RadiusProbeResult> {
    const { data } = await api.post(`/radius-config/${id}/test`);

    return data.data;
  },
};

export default mikrotikService;
