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

/**
 * What a widget's own date-range control offers.
 *
 * `custom` is not a granularity — it has no bucket size of its own — which is
 * why it is a separate type rather than a fifth Granularity. useWidgetRange maps
 * it onto daily buckets.
 */
export type RangePreset = Granularity | 'custom';

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

/**
 * Filters shared by all five sections. One object so it is one state update.
 *
 * The date range is deliberately *not* here any more. It moved into each widget
 * (see useWidgetRange), because a single page-level period made the comparison
 * these pages exist for — this month against the twelve-month trend — impossible
 * to draw. What is left is genuinely page-wide: which database, which branch,
 * and the overdue ledger's own paging.
 */
export interface ReportingFilters {
  /** A connection key, or ALL_DATABASES to merge every one of them. */
  database: string;
  branch: string | null;
  /** Financial only: the branch-collections window. */
  branchPeriod: Granularity;
  branchYear: number;
  overdueSearch: string;
  overduePlanId: number;
  overdueBucket: string;
  overduePage: number;
}

/**
 * A widget's own window, merged over the page filters at request time.
 *
 * `period` drives trend bucketing where a section has a trend; sections that do
 * not simply ignore it, as the backend already does for every parameter a
 * section does not use.
 */
export interface WidgetQuery {
  dateFrom: string;
  dateTo: string;
  period?: Granularity;
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

/**
 * Headline subscriber counts, in this portal's reported vocabulary.
 *
 * `pending` is gone: an application that has not been activated is not a
 * subscriber, and the backend excludes it from every count here. `suspended` and
 * `expired` are gone too — they are reported as `restricted` and `disconnected`,
 * which is what management calls them. See App\Services\Reports\StatusMap.
 */
export interface SubscriberKpis {
  total: number;
  active: number;
  vip: number;
  restricted: number;
  disconnected: number;
  /** Null on a schema that stores no subscription end date. */
  expiring_3day: number | null;
  expiring_7day: number | null;
  new_30day: number;
}

/** The four counters in the summary header at the top of the module. */
export interface BillingSummary {
  active: number;
  vip: number;
  inactive: number;
  pullout: number;
  total: number;
}

export interface StatusCounts {
  total: number;
  active: number;
  vip: number;
  restricted: number;
  disconnected: number;
  inactive: number;
  pullout: number;
  /** Every reported status, largest first. Excluded statuses are already gone. */
  by_status: Record<string, number>;
  /** The source system's own vocabulary, before mapping. */
  raw: Record<string, number>;
  /** How many rows were dropped as not-a-subscriber, so the gap is explainable. */
  excluded: number;
}

/** One row of the complete barangay table — every barangay, not a top ten. */
export interface BarangayRow {
  barangay: string;
  municipality: string;
  province: string;
  total: number;
  active: number;
  vip: number;
  inactive: number;
  pullout: number;
  restricted: number;
  disconnected: number;
}

/** Columns the barangay table can be sorted on. */
export type BarangaySort =
  | 'barangay'
  | 'active'
  | 'vip'
  | 'inactive'
  | 'pullout'
  | 'total';

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
  billing_summary?: BillingSummary;
  status: StatusCounts;
  plans: LabelledCount[];
  /** Every barangay. Replaced the capped `top_barangays`. */
  barangays: BarangayRow[];
  growth: { new_in_range: number; expected_mrc: number | null };
  overdue: OverdueLedger;
  /** GOWISER only: live session states. */
  sessions?: LabelledCount[];
  masked?: string[];
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

/** One collection channel: Cash, PNB or Xendit (plus an Other residue). */
export interface IncomeChannel {
  key: 'cash' | 'pnb' | 'xendit' | 'other';
  label: string;
  count: number;
  total: number;
  share_pct: number;
  /** The raw payment methods that rolled into this channel, for tracing. */
  methods: string[];
}

/**
 * One executive measure.
 *
 * `basis` is not decoration. Three of the four are projections, and a projection
 * shown with the same authority as a measurement is the commonest way a
 * dashboard misleads — so the assumption travels with the number and the UI
 * prints it.
 */
export interface ExecutiveMetric {
  label: string;
  value: number | null;
  basis: string;
  at_risk_accounts?: number;
  at_risk_factor?: number;
}

export interface ExecutiveMetrics {
  prospective_revenue: ExecutiveMetric;
  arpu: ExecutiveMetric;
  collection_efficiency: ExecutiveMetric;
  projected_churn_loss: ExecutiveMetric;
}

export interface ExpenseNature {
  label: string;
  total: number;
  count: number;
  share_pct: number;
  rows: LabelledTotal[];
}

export interface OpexCapex {
  opex: ExpenseNature;
  capex: ExpenseNature;
  total: number;
}

export type Recurrence = 'recurring' | 'non_recurring';

/**
 * One payable obligation for a month.
 *
 * The cost side comes live from the monitored database; the settlement side —
 * `is_paid` and everything under it — is MONITOR's own record, because MONITOR
 * never writes to a source system. `variance` surfaces a disagreement between
 * the two rather than resolving it.
 */
export interface PayableRow extends SourceTagged {
  ref: string;
  label: string;
  type: string;
  recurrence: Recurrence;
  nature: 'opex' | 'capex';
  amount: number;
  count: number;
  period_type: string | null;
  last_booked_at: string | null;

  is_paid: boolean;
  paid_on: string | null;
  paid_amount: number | null;
  reference: string | null;
  note: string | null;
  updated_by: string | null;
  variance: number | null;
}

export interface PayablesLedger {
  month: string;
  month_label: string;
  source: string | null;
  rows: PayableRow[];
  totals: Record<
    'recurring' | 'non_recurring' | 'paid' | 'unpaid',
    { count: number; amount: number }
  >;
  outstanding: number;
  /** Always 'monitor': the tick lives here, not in the operating system. */
  settlement_scope: string;
}

export interface FinancialData extends SectionBase, BranchScoped {
  expense_period: Granularity;
  supports_expenses: boolean;
  kpi: FinancialKpi;
  series: TrendPoint[];
  trend: { period: Granularity; points: TrendPoint[] };
  by_plan: LabelledTotal[];
  by_method: LabelledTotal[];
  by_expense_type: LabelledTotal[];
  payment_notes: LabelledTotal[];

  /** Absent when the role lacks the matching widget permission — see `masked`. */
  income_channels?: IncomeChannel[];
  executive_metrics?: ExecutiveMetrics;
  opex_capex?: OpexCapex;
  payables?: PayablesLedger;

  by_branch: {
    period: Granularity;
    year: number;
    label: string;
    rows: BranchCollectionRow[];
    years: number[];
  };
  periods: SummaryPeriod[];

  /**
   * Widget permissions the caller does not hold, so the UI can say "restricted"
   * rather than "no data". The two mean different things and the second sends
   * someone to check a database that is working.
   */
  masked?: string[];
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

/**
 * Average completion time for one kind of work.
 *
 * `unit` matters: GOWISER stamps actual minutes on site, NETMANAGER ages a
 * ticket in hours. Presenting both as one "turnaround" number would invite
 * comparing quantities that are not comparable, so each row states its own.
 */
export interface TurnaroundByType {
  label: string;
  group: string | null;
  unit: 'minutes' | 'hours';
  closed: number;
  average_minutes?: number | null;
  longest_minutes?: number | null;
  average_hours?: number | null;
  longest_hours?: number | null;
}

export interface OperationsData extends SectionBase, BranchScoped {
  queues: WorkQueue[];
  series: WorkPoint[];
  /** One figure on NETMANAGER; split by queue on GOWISER. */
  turnaround?: Turnaround | { job_orders: Turnaround; service_orders: Turnaround };
  /** Average completion time segmented by work-order type. */
  turnaround_by_type?: TurnaroundByType[];
  recent: WorkRow[];
  has_service_orders: boolean;
  /** GOWISER only. */
  concerns?: LabelledCount[];
  repair_categories?: LabelledCount[];
  masked?: string[];
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

// ── Executive group overview (Module 5) ────────────────────────────────

export interface SubscriberHealth {
  available: boolean;
  active_subscribers?: number;
  vip_subscribers?: number;
  disconnected?: number;
  new_in_range?: number;
  /** New in range minus disconnections — the figure that can go negative. */
  net_growth?: number;
  churn_rate_pct?: number | null;
  billing_summary?: BillingSummary | null;
  range_label?: string;
}

export interface ExecutiveFinancialSummary {
  available: boolean;
  /** True when the role may open this view but not read the money on it. */
  masked?: boolean;
  total_income?: number;
  channels?: Record<string, { label: string; total: number; count: number; share_pct: number }>;
  opex?: number;
  capex?: number;
  net?: number;
  margin_pct?: number | null;
  outstanding_payables?: number;
  payables_unpaid_count?: number;
  metrics?: ExecutiveMetrics | null;
  range_label?: string;
}

/**
 * Something an executive would want to be told about.
 *
 * Not a monitoring feed — neither source system runs one — so each alarm is
 * derived from a condition in the operational data and carries its own `detail`
 * saying what triggered it, rather than presenting as an SNMP trap.
 */
export interface SystemAlarm {
  key: string;
  severity: 'critical' | 'warning' | 'info';
  label: string;
  detail: string;
}

export interface OperationsTechSummary {
  available: boolean;
  open_work: number | null;
  oldest_open_days: number | null;
  average_turnaround: number | null;
  turnaround_unit: 'minutes' | 'hours' | null;
  turnaround_by_type: TurnaroundByType[];
  technicians_live: number | null;
  technicians_reporting: number | null;
  alarms: SystemAlarm[];
  alarm_count: number;
}

export interface ExecutiveOverviewData {
  as_of: string;
  generated_at: string;
  range: DateRange;
  range_label: string;
  subscriber_health: SubscriberHealth;
  financial_summary: ExecutiveFinancialSummary;
  operations_tech: OperationsTechSummary;
  databases: {
    answered: string[];
    answered_labels: string[];
    failed: { key: string; label: string; error: string }[];
    total: number;
  };
  /** Sections that could not be reached, named rather than shown as zero. */
  unavailable: Record<string, string>;
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
