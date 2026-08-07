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

// ── Schema mapping ─────────────────────────────────────────────────────

/**
 * What the reporting drivers expect from a connection, against what is there.
 *
 * Mirrors App\Services\Reports\SchemaMap. The monitored schemas drift and
 * MONITOR cannot migrate them, so this exists to make the drift legible before a
 * figure reads zero rather than after.
 */

/** Which real column a dated figure resolved to, and whether it degraded. */
export interface MappingDate {
  /** The meaning: opened, modified, created, installed. */
  role: string;
  /** The column the driver would rather have had. */
  preferred: string | null;
  /** What it actually found, or null when the schema cannot answer at all. */
  resolved: string | null;
  /** True when it fell back — the figure computes, on a weaker timestamp. */
  degraded: boolean;
}

export interface MappingTable {
  table: string;
  section: string;
  purpose: string;
  exists: boolean;
  column_count: number;
  required: string[];
  /** Required columns this database has not got. */
  missing: string[];
  dates: MappingDate[];
  healthy: boolean;
}

/**
 * One linkage between two expected tables.
 *
 * `kind` matters because the three fail differently — see SchemaMap::RELATIONS.
 * A `match` is joined on text, which is the one that produces plausible-looking
 * wrong totals rather than obviously missing ones.
 */
export interface MappingRelation {
  from: string;
  to: string;
  /** The join, written out. */
  on: string;
  kind: 'fk' | 'lookup' | 'match';
  note: string;
  /** False when either end of the linkage is missing from this database. */
  available: boolean;
}

/** One metric card on the portal, and the tables it is computed from. */
export interface MappingMetric {
  key: string;
  label: string;
  /** Where in the portal this card appears. */
  page: string;
  tables: string[];
  /** The rule behind the figure, in the card's own words. */
  basis: string;
  available: boolean;
  /** Tables this card needs that the database has not got. */
  missing: string[];
}

export interface MappingSummary {
  expected: number;
  present: number;
  missing_tables: number;
  missing_columns: number;
  degraded_dates: number;
}

export interface SchemaMapping {
  driver: string;
  /** False when no expectations are declared for this driver at all. */
  declared: boolean;
  tables: MappingTable[];
  relations: MappingRelation[];
  metrics: MappingMetric[];
  summary?: MappingSummary;
  connection?: string;
  label?: string;
  database?: string;
}

/** Field-level messages from a 422, keyed by form field. */
export type ValidationErrors = Record<string, string[]>;
