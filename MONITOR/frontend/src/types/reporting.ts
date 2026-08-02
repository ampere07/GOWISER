/**
 * Payload shapes for /api/reporting/*.
 *
 * These mirror the reporting drivers exactly. Where a field can be absent from
 * the source data it is typed nullable rather than defaulted here, so a missing
 * value renders as an em dash instead of a confident zero — the two mean
 * different things, and this module is read for decisions.
 *
 * Several fields are nullable specifically because the two schemas differ —
 * NETMANAGER has no technicians, for one.
 * A `supports_*` flag accompanies each such gap so the UI omits a panel rather
 * than showing an empty one that looks like a fault.
 */

/**
 * The reserved source key meaning "every database at once".
 *
 * Not a database — a filter. The backend refuses to let any connection claim it.
 */
export const ALL_DATABASES = 'all';

/**
 * Reported by every section when the answer was merged from several databases.
 *
 * `failed` is the important half: with eight branches, one unreachable host must
 * not silently shorten a total, so the page can say which databases are missing
 * from the figures on screen.
 */
export interface AggregateInfo {
  is_aggregate: true;
  section: ReportingSection;
  answered: string[];
  answered_labels: string[];
  failed: { key: string; label: string; error: string }[];
  total_databases: number;
}

/**
 * Fields the aggregator adds to a row that came from one specific database.
 *
 * Rows about a person or an account cannot be summed, so they are concatenated
 * and tagged — an overdue account stays attributable to the branch that has to
 * chase it.
 */
export interface SourceTagged {
  source?: string;
  source_label?: string;
}

/** The five sections. Matches ReportingService::SECTIONS. */
export type ReportingSection =
  | 'subscriber_analytics'
  | 'financial'
  | 'operations'
  | 'tech'
  | 'employee';

export type Granularity = 'daily' | 'weekly' | 'monthly' | 'yearly';

export interface ReportingSource {
  key: string;
  label: string;
  capabilities: ReportingSection[];
}

/** Which sources can answer each section. */
export type SectionSources = Record<ReportingSection, { key: string; label: string }[]>;

export interface ReportingCapabilities {
  sources: ReportingSource[];
  sections: SectionSources;
}

export interface ReportingBranch {
  id: string;
  label: string;
  location: string | null;
}

export interface DateRange {
  from: string;
  to: string;
}

/** Filters shared by all five sections. One object so it is one state update. */
export interface ReportingFilters {
  /** A connection key, or ALL_DATABASES to merge every one of them. */
  database: string;
  dateFrom: string;
  dateTo: string;
  branch: string | null;
  /** Financial only: granularity of the long-horizon trend. */
  period: Granularity;
  /** Financial only: the branch-collections window. */
  branchPeriod: Granularity;
  branchYear: number;
  overdueSearch: string;
  overduePlanId: number;
  overdueBucket: string;
  overduePage: number;
}

// ── Shared row shapes ──────────────────────────────────────────────────

export interface TrendPoint {
  period: string;
  label: string;
  income: number;
  expenses: number;
  net: number;
}

export interface WorkPoint {
  period: string;
  label: string;
  opened: number;
  closed: number;
}

export interface LabelledCount {
  label: string;
  count: number;
}

export interface LabelledTotal {
  label: string;
  count: number;
  total: number;
  /** Second line under the label, when the driver supplies one. */
  detail?: string;
}

/** Common envelope every section payload shares. */
interface SectionBase {
  as_of: string;
  generated_at: string;
  range: DateRange;
  range_label: string;
  /** Present only when the payload was merged from several databases. */
  aggregate?: AggregateInfo;
}

interface BranchScoped {
  branch: string | null;
  branch_label: string;
}

// ── Subscriber analytics ───────────────────────────────────────────────

export interface SubscriberKpis {
  total: number;
  active: number;
  vip: number;
  inactive: number;
  pullout: number;
  /** Named for what the business calls them; GOWISER stores these as Suspended and Overdue. */
  restricted: number;
  disconnected: number;
  /** Null on GOWISER, which stores no subscription end date. */
  expiring_3day: number | null;
  expiring_7day: number | null;
  new_30day: number;
  /** GOWISER only: accounts carrying a balance, and what they owe. */
  in_arrears?: number;
  receivables?: number;
}

export interface StatusCounts {
  total: number;
  active: number;
  vip: number;
  inactive: number;
  pullout: number;
  restricted: number;
  disconnected: number;
  /** Every status the data actually holds, including ones not named above. */
  by_status: Record<string, number>;
}

export interface BarangayRow {
  barangay: string;
  municipality: string;
  province: string;
  total: number;
  active: number;
  vip: number;
  inactive: number;
  pullout: number;
}

export interface OverdueRow extends SourceTagged {
  id: string;
  account_number: string;
  subscriber: string;
  contact_number: string;
  plan: string;
  /** The plan's monthly charge, or the outstanding balance on GOWISER. */
  mrc: number;
  expired_on: string | null;
  /**
   * Null on GOWISER, where "overdue" means an outstanding balance rather than a
   * elapsed date, so there is no day count to report. Not the same as having no
   * expiry date at all — prepaid accounts do carry one, and it drives the
   * expiring-in-N-days figures above.
   */
  days_overdue: number | null;
  status?: string;
}

export interface OverdueLedger {
  rows: OverdueRow[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  filters: { search: string; plan_id: number; bucket: string };
  plans: { id: number; label: string }[];
  /** 'days' ages by expiry date; 'balance' bands by amount owed. */
  bucket_kind?: 'days' | 'balance';
}

export interface SubscriberAnalyticsData extends SectionBase, BranchScoped {
  kpi: SubscriberKpis;
  status: StatusCounts;
  plans: LabelledCount[];
  top_barangays: BarangayRow[];
  growth: { new_in_range: number; expected_mrc: number | null };
  overdue: OverdueLedger;
  /** GOWISER only: live session states. */
  sessions?: LabelledCount[];
}

// ── Financial ──────────────────────────────────────────────────────────

export interface FinancialKpi {
  income: number;
  income_count: number;
  average_payment: number;
  largest_payment: number;
  office_income: number;
  office_count: number;
  portal_income: number;
  portal_count: number;
  office_by_type: LabelledTotal[];
  expenses: number;
  expenses_count: number;
  net: number;
  margin_pct: number | null;
  expected_mrc: number;
  collection_rate: number;
}

export interface BranchCollectionRow extends SourceTagged {
  id: string;
  label: string;
  location: string | null;
  collection: number;
  subscribers: number;
  share_pct: number;
}

export interface SummaryPeriod {
  key: Granularity;
  label: string;
  accent: string;
  date_label: string;
  range: DateRange;
  income: number;
  payment_count: number;
  expenses: number;
  expenses_count: number;
  net: number;
  /** Margin when in surplus, loss ratio when not. Null when there was no income. */
  ratio_pct: number | null;
}

export interface RecentPayment extends SourceTagged {
  id: string;
  or_number: string;
  account_number: string;
  subscriber: string;
  amount: number;
  method: string;
  status: string;
  payment_date: string | null;
}

export interface FinancialData extends SectionBase, BranchScoped {
  expense_period: Granularity;
  supports_expenses: boolean;
  kpi: FinancialKpi;
  series: TrendPoint[];
  trend: { period: Granularity; points: TrendPoint[] };
  /**
   * Income split by payment channel. Fixed keys, always all four present — `other` carries
   * everything that is not one of the three named channels, so the parts sum to `kpi.income`.
   */
  by_channel: Record<'cash' | 'pnb' | 'xendit' | 'other', { amount: number; count: number }>;
  by_plan: LabelledTotal[];
  by_method: LabelledTotal[];
  by_expense_type: LabelledTotal[];
  payment_notes: LabelledTotal[];
  by_branch: {
    period: Granularity;
    year: number;
    label: string;
    rows: BranchCollectionRow[];
    years: number[];
  };
  periods: SummaryPeriod[];
  recent_payments: RecentPayment[];
}

// ── Operations ─────────────────────────────────────────────────────────

export interface QueueBacklog {
  open: number;
  oldest_opened_at: string | null;
  oldest_age_days: number | null;
}

export interface WorkQueue {
  key: string;
  label: string;
  statuses: LabelledCount[];
  backlog: QueueBacklog;
}

export interface Turnaround {
  closed: number;
  /** NETMANAGER measures ticket age in hours; GOWISER measures onsite minutes. */
  average_hours?: number | null;
  longest_hours?: number | null;
  average_minutes?: number | null;
  longest_minutes?: number | null;
}

export interface WorkRow extends SourceTagged {
  id: string;
  status: string;
  remark: string;
  account_number: string;
  subscriber: string;
  location: string | null;
  plan: string;
  assignee: string;
  opened_at: string | null;
  updated_at: string | null;
}

export interface OperationsData extends SectionBase, BranchScoped {
  queues: WorkQueue[];
  series: WorkPoint[];
  /** One figure on NETMANAGER; split by queue on GOWISER. */
  turnaround: Turnaround | { job_orders: Turnaround; service_orders: Turnaround };
  recent: WorkRow[];
  has_service_orders: boolean;
  /** GOWISER only. */
  concerns?: LabelledCount[];
  repair_categories?: LabelledCount[];
}

// ── Tech ───────────────────────────────────────────────────────────────

export interface Technician extends SourceTagged {
  id: string;
  name: string;
  initial: string;
  updated_at: string | null;
}

export interface TechnicianWorkload extends SourceTagged {
  id: string;
  name: string;
  job_orders: number;
  job_orders_done: number;
  service_orders: number;
  service_orders_done: number;
  total: number;
  completed: number;
  average_minutes: number | null;
}

export interface TechnicianLocation extends SourceTagged {
  user_id: string;
  name: string;
  latitude: number | null;
  longitude: number | null;
  accuracy_m: number | null;
  speed: number | null;
  /** What the device last claimed. Trust `is_live` instead. */
  reported_status: string;
  last_seen_at: string | null;
  minutes_ago: number | null;
  /** Derived from last_seen_at, not from reported_status. */
  is_live: boolean;
}

export interface TechData extends SectionBase {
  roster: Technician[];
  roster_count: number;
  workload: TechnicianWorkload[];
  locations: TechnicianLocation[];
  /** Work in the range with nobody recorded against it. */
  unattributed: { job_orders: number; service_orders: number };
  turnaround: { job_orders: Turnaround; service_orders: Turnaround };
}

// ── Employee ───────────────────────────────────────────────────────────

export interface StaffMember extends SourceTagged {
  id: string;
  name: string;
  username: string;
  email: string;
  role: string;
  branch: string;
  active: boolean;
  last_login?: string | null;
}

export interface RoleCount {
  label: string;
  count: number;
  active: number;
}

export interface CollectionByUser extends SourceTagged {
  label: string;
  role: string;
  count: number;
  total: number;
}

export interface WorkByUser extends SourceTagged {
  label: string;
  role: string;
  assigned: number;
  completed: number;
  average_hours: number | null;
}

export interface EmployeeData extends SectionBase, BranchScoped {
  roster: StaffMember[];
  by_role: RoleCount[];
  collections: CollectionByUser[];
  field_work: WorkByUser[];
  payees: LabelledTotal[];
  /** False on GOWISER, which has no expense ledger. */
  supports_payees: boolean;
}

// ── Printable ──────────────────────────────────────────────────────────

export interface PrintCompany {
  name: string;
  description: string;
  address: string;
  contact: string;
  email: string;
  tin: string;
  logo: string;
  currency_symbol: string;
  manager: string;
}

export interface PrintPaymentLine {
  or_number: string;
  account_number: string;
  subscriber: string;
  type: string;
  method: string;
  status: string;
  amount: number;
  payment_date: string | null;
  cashier: string;
}

export interface PrintExpenseLine {
  expense_date: string | null;
  type: string;
  employee: string;
  remark: string;
  period_type: string;
  amount: number;
  recorded_by: string;
}

export interface PrintableData {
  range: DateRange;
  range_label: string;
  expense_period: Granularity;
  branch: string | null;
  branch_label: string;
  generated_at: string;
  company: PrintCompany;
  payments: PrintPaymentLine[];
  expenses: PrintExpenseLine[];
  payment_notes: LabelledTotal[];
  totals: {
    income: number;
    income_count: number;
    expenses: number;
    expenses_count: number;
    net: number;
  };
}

/** Which of the three print layouts to render. */
export type PrintLayout = 'financial' | 'payments' | 'expenses';

/**
 * Every section response names the source that answered it.
 *
 * `source` and `requested_source` differ when a section could not be served by
 * the selected system and fell through to one that could — Tech on GOWISER while
 * the rest of the app points at NETMANAGER. The UI surfaces that rather than
 * letting the substitution pass unnoticed.
 */
export interface ReportingResponse<T> {
  status: string;
  source: string;
  source_label: string;
  requested_source?: string;
  section?: ReportingSection;
  data: T;
}
