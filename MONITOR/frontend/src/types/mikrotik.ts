/**
 * The Mikrotik User Manager, as MONITOR's API reports it.
 *
 * RouterOS returns kebab-case keys, a leading-dot id and different spellings
 * between major versions; the backend normalises all of that once
 * (MikrotikController's shapers) so these types describe one stable shape and a
 * RouterOS upgrade is not a frontend change.
 */

/**
 * A block of rows that knows whether it is complete.
 *
 * `reachable: false` with an empty `rows` is not the same claim as `reachable:
 * true` with an empty `rows` — one means the router did not answer, the other
 * means it answered "none". A screen that rendered both as an empty table would
 * tell an operator their groups had been deleted during an outage.
 */
export interface MikrotikBlock<T> {
  reachable: boolean;
  errors: Record<string, string>;
  rows: T[];
}

export interface MikrotikGroup {
  id: string;
  name: string;
  /** "150M/150M" — receive/transmit, as RouterOS writes Mikrotik-Rate-Limit. */
  rate_limit: string;
  /** "pool-nat444" and the like. */
  framed_pool: string;
  shared_users: string;
  comment: string;
}

export interface MikrotikProfile {
  id: string;
  name: string;
  price: string;
  validity: string;
  starts_at: string;
}

export interface MikrotikLimitation {
  id: string;
  name: string;
  rate_limit: string;
  download_limit: string;
  upload_limit: string;
  uptime_limit: string;
}

export interface MikrotikUser {
  id: string;
  username: string;
  group: string;
  shared_users: string;
  disabled: boolean;
  comment: string;
}

export interface MikrotikSession {
  id: string;
  user: string;
  group: string;
  address: string;
  nas: string;
  uptime: string;
  started: string;
}

/** One queued session termination, waiting for the maintenance window. */
export interface MikrotikQueuedKick {
  id: number;
  target: string;
  group: string | null;
  reason: string | null;
  status: 'pending' | 'running' | 'done' | 'failed' | 'cancelled';
  requested_by: string | null;
  scheduled_for: string | null;
  executed_at: string | null;
  sessions_killed: number;
  sessions_failed: number;
  result_note: string | null;
}

export interface MikrotikOverview {
  /** False when no RADIUS server is configured — the page says so rather than erroring. */
  configured: boolean;
  message?: string;
  servers: { key: string; label: string }[];
  groups: MikrotikBlock<MikrotikGroup>;
  profiles: MikrotikBlock<MikrotikProfile>;
  limitations: MikrotikBlock<MikrotikLimitation>;
  sessions: MikrotikBlock<MikrotikSession>;
  queued: MikrotikQueuedKick[];
  maintenance_window?: {
    start: string;
    end: string;
    next: string;
    open_now: boolean;
  };
}

export interface MikrotikUserList {
  reachable: boolean;
  errors: Record<string, string>;
  total: number;
  rows: MikrotikUser[];
}
