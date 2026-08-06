/**
 * The MikroTik User Manager, as MONITOR's API reports it.
 *
 * RouterOS returns kebab-case keys, a leading-dot id and different spellings
 * between major versions; the backend normalises all of that once
 * (MikrotikRadiusService's shapers) so these types describe one stable shape and
 * a RouterOS upgrade is not a frontend change.
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
  /** "250M/250M" — receive/transmit, as RouterOS writes Mikrotik-Rate-Limit. */
  rate_limit: string;
  /** "pool-nat444" and the like. */
  framed_pool: string;
  shared_users: string;
  outer_auths: string;
  inner_auths: string;
  attributes: string;
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

/**
 * One RADIUS dictionary entry — which attributes this User Manager can send.
 *
 * Read-only. Editing the dictionary is a router-configuration job rather than a
 * subscriber one, and a wrong entry breaks authentication for everyone rather
 * than for a group.
 */
export interface MikrotikAttribute {
  id: string;
  name: string;
  type_id: string;
  value_type: string;
  vendor_id: string;
  packet_types: string;
}

export interface MikrotikUser {
  id: string;
  username: string;
  group: string;
  shared_users: string;
  /** The MAC or circuit actually connected, when there is a live session. */
  caller_id: string;
  attributes: string;
  disabled: boolean;
  comment: string;
  /** Live sessions for this user right now. */
  sessions: number;
  online: boolean;
  address: string;
  uptime: string;
}

export interface MikrotikSession {
  id: string;
  user: string;
  group: string;
  address: string;
  caller_id: string;
  nas: string;
  uptime: string;
  started: string;
  download: string;
  upload: string;
}

/** One queued session termination. */
export interface MikrotikQueuedKick {
  id: number;
  target: string;
  group: string | null;
  usernames: string[];
  reason: string | null;
  status: 'pending' | 'running' | 'done' | 'failed' | 'cancelled';
  /** `at` — a named GMT+8 time. `window` — the next maintenance window. */
  mode: 'at' | 'window';
  requested_by: string | null;
  /** Always rendered in Asia/Manila by the API, whatever the server runs in. */
  scheduled_for: string | null;
  scheduled_timezone: string;
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
  /** 'settings' once RADIUS API rows exist, 'environment' while env vars are in use. */
  source?: 'settings' | 'environment';
  groups: MikrotikBlock<MikrotikGroup>;
  profiles: MikrotikBlock<MikrotikProfile>;
  limitations: MikrotikBlock<MikrotikLimitation>;
  /**
   * Optional: RouterOS 6 does not expose the dictionary at all, and the tab
   * says so rather than drawing an empty table that implies it is empty.
   */
  attributes?: MikrotikBlock<MikrotikAttribute>;
  sessions: MikrotikBlock<MikrotikSession>;
  /** Live sessions per group name — the blast radius of a group change. */
  sessions_by_group: Record<string, number>;
  queued: MikrotikQueuedKick[];
  maintenance_window?: {
    start: string;
    end: string;
    next: string;
    open_now: boolean;
  };
  timezone?: {
    name: string;
    label: string;
    now: string;
  };
}

export interface MikrotikUserList {
  reachable: boolean;
  errors: Record<string, string>;
  total: number;
  rows: MikrotikUser[];
  truncated: boolean;
}

/**
 * What a typed rate limit resolves to.
 *
 * Produced by the server, never by the browser: two implementations of this
 * conversion would eventually disagree, and the one that disagreed silently
 * would be the one that set the speed.
 */
export interface RateLimitPreview {
  /** RouterOS form: "250M/50M". */
  value: string;
  rx_bps: number;
  tx_bps: number;
  /** Set when the result is implausibly low — usually a missing unit. */
  warning: string | null;
}

/** The result of changing a group, including how many sessions it stranded. */
export interface GroupUpdateResult {
  server: string;
  before: MikrotikGroup;
  after: MikrotikGroup;
  rate_limit: RateLimitPreview | null;
  live_sessions: number;
  note: string;
}

/** The result of moving one user between groups. */
export interface UserMoveResult {
  server: string;
  before: MikrotikUser;
  after: MikrotikUser;
  live_sessions: number;
  note: string;
}

// ── RADIUS API settings ────────────────────────────────────────────────

/**
 * One configured RADIUS endpoint.
 *
 * `has_password` rather than the password itself. The secret is never sent to
 * the browser, and a row of asterisks in a form field would be submitted back as
 * though it were real.
 */
export interface RadiusServerConfig {
  id: number;
  /** 1 is primary, 2 is secondary — the failover order. */
  position: number;
  key: string;
  label: string | null;
  ssl_type: 'http' | 'https';
  ip: string;
  port: string;
  username: string;
  has_password: boolean;
  is_active: boolean;
  base_url: string | null;
  updated_by: string | null;
  updated_at: string | null;
}

export interface RadiusConfigList {
  servers: RadiusServerConfig[];
  max_servers: number;
  /** Where the live fleet is coming from right now. */
  source: 'settings' | 'environment';
  configured: boolean;
}

export interface RadiusServerFormValues {
  ssl_type: 'http' | 'https';
  ip: string;
  port: string;
  username: string;
  /** Blank on an update means "leave the stored one alone". */
  password: string;
  label: string;
  is_active: boolean;
}

export interface RadiusProbeResult {
  online: boolean;
  latency_ms: number | null;
  error: string | null;
  base_url: string;
}
