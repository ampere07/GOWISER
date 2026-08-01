import { ReportingSection } from './reporting';

/**
 * Shapes for /api/databases/*, the Databases configuration page.
 *
 * The only writing endpoints in MONITOR. Note what is absent: there is no
 * password field on any response type. The API never returns a stored
 * credential, so the page reports only whether one is set.
 */

export interface SchemaProfileOption {
  key: string;
  label: string;
  description: string | null;
  is_system: boolean;
}

/** Row-level separation for several operating companies sharing one database. */
export interface ConnectionScope {
  column: string;
  value: string;
}

export interface DatabaseConnection {
  id: number;
  /** Slug used as ?source= and as the Laravel connection name. */
  key: string;
  label: string;
  profile_key: string;
  profile_label: string;
  host: string;
  port: number;
  database: string;
  username: string;
  timezone: string;
  enabled: boolean;
  sort_order: number;
  /** Whether a credential is stored. The credential itself is never returned. */
  has_password: boolean;
  scope: ConnectionScope | null;
  /** Result of the last connectivity check: 'ok', 'failed', or null if never run. */
  last_status: string | null;
  last_error: string | null;
  last_checked_at: string | null;
  /** Reporting sections this database can actually serve. */
  sections: ReportingSection[];
}

export interface ConnectionTestResult {
  ok: boolean;
  tables: number;
  message: string;
}

export interface DatabaseListResponse {
  connections: DatabaseConnection[];
  profiles: SchemaProfileOption[];
  /**
   * True when no enabled connection exists, which means the portal has no data
   * at all — this table is the only place monitored databases are defined. The
   * page states it, because an empty list on its own is ambiguous.
   */
  no_sources: boolean;
}

/** The add/edit form. Password is write-only and blank means "leave unchanged". */
export interface ConnectionFormValues {
  key: string;
  label: string;
  profile_key: string;
  host: string;
  port: number;
  database: string;
  username: string;
  password: string;
  timezone: string;
  enabled: boolean;
  scope_column: string;
  scope_value: string;
}

export interface IntrospectTable {
  name: string;
  approx_rows: number;
}

export interface IntrospectColumn {
  name: string;
  type: string;
  nullable: boolean;
}

export interface IntrospectResult {
  tables?: IntrospectTable[];
  table?: string;
  columns?: IntrospectColumn[];
}

/** Field-level messages from a 422, keyed by form field. */
export type ValidationErrors = Record<string, string[]>;
