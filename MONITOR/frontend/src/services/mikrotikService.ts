import api from '../config/api';
import {
  MikrotikGroup,
  MikrotikOverview,
  MikrotikUserList,
} from '../types/mikrotik';

/**
 * The Mikrotik Radius Shortcut's reads and writes.
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

  async users(search?: string): Promise<MikrotikUserList> {
    const { data } = await api.get('/mikrotik/users', {
      params: search ? { search } : undefined,
    });

    return data.data;
  },

  /**
   * Changes a group's rate limit and/or framed pool.
   *
   * Returns the before and after as the *router* reports them, not as they were
   * submitted — RouterOS normalises some values, and showing the request back as
   * if it were the result would let the screen disagree with the device.
   */
  async updateGroup(
    group: string,
    changes: { rate_limit?: string; framed_pool?: string; comment?: string }
  ): Promise<{
    server: string;
    before: MikrotikGroup;
    after: MikrotikGroup;
    live_sessions: number;
    note: string;
  }> {
    const { data } = await api.put(`/mikrotik/groups/${encodeURIComponent(group)}`, changes);

    return data.data;
  },

  /** Disconnects matching sessions immediately. */
  async kickNow(target: { group?: string; usernames?: string[]; reason?: string }) {
    const { data } = await api.post('/mikrotik/kick/now', target);

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

export default mikrotikService;
