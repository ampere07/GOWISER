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

/** One counter in the billing summary header. */
export interface BillingSummaryCard {
  key: string;
  label: string;
  count: number;
}

/**
 * The summary header at the top of the module.
 *
 * A list rather than four fixed fields, because the cards are whatever billing
 * statuses the source actually holds. SYNC's `billing_status` table is a lookup
 * an operator edits: hardcoding four names meant a status SYNC uses but the list
 * did not name vanished, while a name SYNC does not use rendered as a confident
 * zero — which reads as "none of these" rather than "not tracked here".
 */
export interface BillingSummary {
  cards: BillingSummaryCard[];
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
  /**
   * The network split, over the same rows as the billing split beside it.
   *
   * Mutually exclusive and in this precedence: disconnected, then restricted,
   * then online, then offline — so the four sum to `total` exactly as the
   * billing columns do. See GowiserReportsDriver::barangayBreakdown.
   */
  online: number;
  offline: number;
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

/** Columns the executive dashboard's network-oriented barangay table sorts on. */
export type BarangayNetworkSort =
  | 'barangay'
  | 'online'
  | 'offline'
  | 'restricted'
  | 'disconnected'
  | 'total';

/** One plan's share of the subscriber base. */
export interface PlanShare {
  /**
   * Null on the "Unmapped / Legacy Plan" bucket, which is not a plan and has no
   * id in any database — see App\Services\Reports\PlanReconciler.
   */
  plan_id: number | null;
  label: string;
  price: number;
  count: number;
  share_pct: number;
  /**
   * On the unmapped bucket only: a few of the raw legacy strings that could not
   * be matched, so the fix is a lookup rather than a database trawl.
   */
  samples?: string[];
}

/** Columns the plan distribution table sorts on. */
export type PlanSort = 'label' | 'share_pct' | 'count';

/**
 * Online, offline, restricted and disconnected over one population.
 *
 * The four are mutually exclusive and sum to `total`, which the old pairing of
 * RADIUS session counts with billing status counts did not — they were two
 * different populations presented as one four-way split.
 */
export interface NetworkStatus {
  online: number;
  offline: number;
  restricted: number;
  disconnected: number;
  total: number;
  /**
   * Accounts SYNC's RADIUS sync could not find a RADIUS user for. A subset of
   * `offline`, not a fifth bucket — the four still sum to `total`. Reported
   * because it is a provisioning fault rather than a subscriber who happens to
   * be switched off, and folding it in silently hides a real backlog.
   */
  not_found?: number;
  /** Accounts never written to online_status at all. Also a subset of offline. */
  no_session_row?: number;
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
  billing_summary?: BillingSummary;
  status: StatusCounts;
  plans: LabelledCount[];
  /** Every plan with its share, uncapped — drives the pie and its table. */
  plan_distribution?: PlanShare[];
  /** GOWISER only: the four network counts taken over one row set. */
  network?: NetworkStatus;
  /** Subscribers the SYNC platform fee is charged for (excludes VIP, Pullout). */
  sync_billable_accounts?: number | null;
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
  daily_average?: number;
  projected_monthly?: number;
  days_elapsed?: number;
  days_in_month?: number;
}

/**
 * Collections anchored on the calendar, not on the widget's range.
 *
 * Both figures here deliberately ignore the selected window. "Projected monthly"
 * is defined against the current month, and computing it from an arbitrary range
 * projects a month that is not the one being projected — on a Daily view it was
 * one day's takings times thirty-one. The weekly average is the last seven
 * calendar days divided by seven, always, so a Sunday with no counter takings
 * stays in the denominator where it belongs.
 *
 * `kpi.daily_average` still follows the range: the Financial module's own panel
 * is about the window someone selected, and that is a different question.
 */
export interface RollingIncome {
  month_start: string | null;
  as_of: string | null;
  month_income: number;
  days_elapsed: number;
  days_in_month: number;
  daily_average: number;
  projected_monthly: number;
  week_from: string | null;
  week_income: number;
  /** Always 7. Named so the divisor can be stated rather than assumed. */
  week_days: number;
  weekly_average: number;
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

/**
 * One collection channel: Cash, PNB or the Payment Portal (plus an Other
 * residue).
 *
 * The portal channel is keyed and labelled for the route rather than the
 * gateway behind it — SYNC's online collections have run through more than one
 * provider, and naming the channel after the current one guarantees a rename.
 */
export interface IncomeChannel {
  key: 'cash' | 'pnb' | 'portal' | 'other';
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
  /** Month-to-date and seven-day figures, anchored on today rather than the range. */
  rolling?: RollingIncome;
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

/** One day on the three-stream timeline. */
export interface WorkTimelinePoint {
  period: string;
  label: string;
  applications: number;
  job_orders: number;
  service_orders: number;
}

export interface OperationsData extends SectionBase, BranchScoped {
  queues: WorkQueue[];
  /** GOWISER only: the three streams bucketed into the reporting vocabulary. */
  work_streams?: Record<string, { key: string; label: string; count: number; total?: number; buckets: Record<string, number>; statuses: LabelledCount[] }>;
  work_timeline?: WorkTimelinePoint[];
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

/** Detailed subscriber breakdown for the Executive Dashboard. */
export interface SubscriberOverview {
  available: boolean;
  billing?: {
    active: number;
    inactive: number;
    vip: number;
    pullout: number;
    total: number;
  };
  /**
   * Null when no monitored schema can produce it. Not four zeros — "we could
   * not ask" and "nobody is online" are different claims.
   */
  network?: NetworkStatus | null;
  /** Prepaid accounts lapsing inside the window; null on a schema with no expiry. */
  expiring?: {
    in_3_days: number | null;
    in_7_days: number | null;
  };
  plan_distribution?: PlanShare[];
  barangays?: BarangayRow[];
  /** Raw RADIUS session states, kept as network telemetry rather than a status. */
  sessions?: LabelledCount[];
  /** VIP-status and free-plan accounts, for the Group Overview. */
  free_connections?: FreeConnections;
  /**
   * Who the VIP counter is counting, by name.
   *
   * Null — not an empty list — on a source with no VIP concept. "No VIP
   * accounts" and "this system does not model VIP" are different claims, and
   * the panel says which.
   */
  vip_accounts?: VipAccountList | null;
}

/** One account exempted from billing by a VIP billing status. */
export interface VipAccount {
  id: string;
  account_number: string;
  subscriber: string;
  contact_number: string;
  barangay: string;
  plan: string;
  /** Null for an open-ended VIP, which is a real state rather than missing data. */
  vip_expiration: string | null;
  date_installed: string | null;
  /** Set only on an aggregate response — an account number is unique per system. */
  source?: string;
  source_label?: string;
}

export interface VipAccountList {
  rows: VipAccount[];
  /** The true count, which exceeds rows.length when the list was capped. */
  total: number;
  truncated: boolean;
}

/** One work-order stream — job orders or service orders. */
export interface WorkOrderStream {
  label: string;
  count: number;
  done: number;
  reschedule: number;
  failed: number;
  in_progress: number;
  other: number;
  statuses: LabelledCount[];
}

/** The applications stream, which carries the brief's formula as well as a count. */
export interface ApplicationStream {
  /** (rescheduled + in progress + new apply) − failed, floored at zero. */
  total: number;
  /** Rows in the range, which is a different question from `total`. */
  count: number;
  rescheduled: number;
  in_progress: number;
  new_apply: number;
  failed: number;
  other: number;
  statuses: LabelledCount[];
  formula: string;
}

/**
 * Applications, job orders and service orders, reported separately.
 *
 * `available` is false rather than three zeros where no monitored schema models
 * these tables — reporting zero applications for a system that has no concept of
 * them is a claim, not a measurement.
 */
export interface WorkStreams {
  available: boolean;
  range?: DateRange;
  range_label?: string;
  applications?: ApplicationStream;
  job_orders?: WorkOrderStream;
  service_orders?: WorkOrderStream;
  timeline?: WorkTimelinePoint[];
  /** Today / this week / this month, independent of the range above. */
  cadence?: WorkCadence;
  resolution?: ResolutionSummary;
}

/** The three fixed windows every cadence figure is reported over. */
export type CadenceWindow = 'today' | 'week' | 'month';

export interface CadenceWindowMeta {
  key: CadenceWindow;
  label: string;
  from: string;
  to: string;
}

/**
 * One queue's bucket counts, per window.
 *
 * Indexed rather than enumerated because the bucket names come from server
 * config (reporting.application_cadence_buckets, reporting.work_order_buckets)
 * and a status added there must not need a frontend deploy to be counted.
 * `total` and `other` are always present.
 */
export type CadenceTally = Record<string, number>;

export interface QueueCadence {
  today: CadenceTally;
  week: CadenceTally;
  month: CadenceTally;
}

/**
 * All three queues on today / this week / this month.
 *
 * Empty when no monitored schema models any of these tables — which the cards
 * report as "not tracked here" rather than as zeros.
 */
export interface WorkCadence {
  windows?: Record<CadenceWindow, CadenceWindowMeta>;
  applications?: QueueCadence;
  job_orders?: QueueCadence;
  service_orders?: QueueCadence;
}

/** One ticket that has been open longest across the whole fleet. */
export interface OutstandingTicket {
  queue: string;
  /** "Installation" or "Repair". */
  label: string;
  reference: string;
  account_no: string;
  customer: string;
  status: string;
  opened_at: string;
  hours: number;
  days: number;
  source?: string;
  source_label?: string;
}

export interface ResolutionSummary {
  available: boolean;
  completed: Record<CadenceWindow, { installations: number; repairs: number; total: number }>;
  longest_outstanding: OutstandingTicket | null;
}

/** Subscribers who are not billed — VIP status, or a plan named for it. */
export interface FreeConnections {
  vip_status_accounts: number;
  free_plan_accounts: number;
  plans: LabelledCount[];
  /**
   * The larger of the two counts, not their sum: they overlap and the overlap
   * cannot be measured from these aggregates, so summing would double-count.
   */
  total: number;
  overlaps: boolean;
}

/** The `/executive/work-streams` response — one window, all three streams. */
export interface WorkStreamsResponse {
  range: DateRange;
  range_label: string;
  streams: WorkStreams;
  unavailable: Record<string, string>;
}

/**
 * The SYNC platform fee.
 *
 * `configured: false` means no rate has been set, and `total` is null rather
 * than 0 — under a Net Income figure, zero would be a claim that SYNC is free.
 */
export interface SyncPriceLine {
  configured: boolean;
  rate: number;
  billable_accounts: number | null;
  excluded_statuses: string[];
  total: number | null;
}

/**
 * The hosting fee, a flat monthly infrastructure charge.
 *
 * `configured: false` means no rate has been set. `total` equals the rate
 * (no multiplier) and is null when not configured.
 */
export interface HostingFeeLine {
  configured: boolean;
  rate: number;
  total: number | null;
}

export interface ExecutiveFinancialSummary {
  available: boolean;
  /** True when the role may open this view but not read the money on it. */
  masked?: boolean;
  /** Cash + PNB + Payment Portal, matching the Financial headline. */
  total_income?: number;
  /** Every channel including the unmatched residue, for reconciliation. */
  income_all_channels?: number;
  /** Collections whose payment method matched no configured channel. */
  unmatched_income?: number;
  channels?: Record<string, { label: string; total: number; count: number; share_pct: number }>;
  opex?: number;
  capex?: number;
  /**
   * MONTHLY charges, whatever the page range is — and netted off nothing.
   *
   * Both are fixed-period costs on a page whose period moves. Subtracting a
   * month's charge from a day's income is not a smaller number but a wrong one,
   * and on a yearly range it understates the cost twelvefold the other way, so
   * no "net after platform costs" figure is produced at all. They are reported
   * as monthly amounts and left for the reader to compare against whichever
   * period they actually mean.
   */
  sync_price?: SyncPriceLine;
  hosting_fee?: HostingFeeLine;
  /**
   * OpEx + CapEx. Deliberately excludes the SYNC and hosting fees, which are
   * reported beside it rather than inside it — see
   * ExecutiveOverviewService::financialSummary.
   */
  total_expenses?: number;
  gross?: number;
  /** Total income − total expenses. Unchanged formula. */
  net?: number;
  margin_pct?: number | null;
  /** Anchored on the current month, never on the widget's range. */
  month_income?: number | null;
  days_elapsed?: number | null;
  days_in_month?: number | null;
  daily_average?: number | null;
  projected_monthly?: number | null;
  /** Strict seven-day rolling average: last 7 days ÷ 7, always. */
  weekly_average?: number | null;
  /** The same figure multiplied back out: the last 7 days' takings in full. */
  week_income?: number | null;
  /** Always 7. Named so the screen can state the divisor rather than imply it. */
  week_days?: number | null;
  week_from?: string | null;
  outstanding_payables?: number;
  payables_unpaid_count?: number;
  metrics?: ExecutiveMetrics | null;
  range_label?: string;
  by_plan?: LabelledTotal[];
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
  /** The date before which operational timestamps are not trusted, or null. */
  reliable_from: string | null;
  /** True when an age was clamped to that floor rather than measured. */
  age_bounded: boolean;
  /** Reported concerns from operations section. */
  concerns?: LabelledCount[];
  /** Repair category breakdown from operations section. */
  repair_categories?: LabelledCount[];
  /** Top technicians ranked by completed work. */
  top_tech?: TechnicianWorkload[];
}

// ── Executive Group Overview (daily flash) ─────────────────────────────
//
// Four blocks of bare numbers. Every value is a number or null — never a
// pre-formatted string — so the screen decides how to render it and a figure
// that could not be measured stays distinguishable from a measured zero.

/** The timeframes the global date toolbar offers. */
export type ExecutiveTimeframe = 'daily' | 'weekly' | 'monthly' | 'yearly' | 'custom';

/** One of the windows the flash screen reports over. */
export interface ExecutiveWindow {
  key: string;
  label: string;
  from: string;
  to: string;
  /** "Aug 06, 2026", "Last 7 days", "August 2026 to date". */
  label_long: string;
}

/** Fields every money block carries, whether or not it could be built. */
interface ExecutiveMoneyBlock {
  available: boolean;
  /** True when the role may open this view but not read the money on it. */
  masked?: boolean;
  range?: DateRange | null;
  range_label?: string;
  /** Collections whose payment method matched no configured channel. */
  unmatched?: number;
  opex?: number | null;
  capex?: number | null;
}

/** Today: the headline trio, the three channels, and the two rate figures. */
export interface ExecutiveDaily extends ExecutiveMoneyBlock {
  income?: number;
  expenses?: number;
  net?: number;
  /** (month-to-date ÷ days elapsed) × days in month. Not today's figure. */
  monthly_projected_sales?: number | null;
  office_collection?: number;
  pnb?: number;
  xendit?: number;
  /** The last seven days ÷ 7. Not today's figure. */
  daily_sales_average?: number | null;
}

/** Year to date, carried inside the monthly block as the layout states it. */
export interface ExecutiveYearly extends ExecutiveMoneyBlock {
  income?: number;
  expenses?: number;
  net?: number;
}

/** Month to date, plus the yearly trio. */
export interface ExecutiveMonthly extends ExecutiveMoneyBlock {
  total_income?: number;
  total_expenses?: number;
  net_income?: number;
  /** The last seven days in full — the daily rate multiplied back out. */
  weekly_sales_average?: number | null;
  total_cash?: number;
  total_pnb?: number;
  total_xendit?: number;
  /**
   * Absent when the block is masked.
   *
   * Optional rather than always-present because the server strips the whole
   * money payload for a role without `widget.executive.finance`, and a type that
   * promised this key would be lying about exactly the case the UI has to handle.
   */
  yearly?: ExecutiveYearly;
}

/**
 * The billing-status headcount and the field force's day and month.
 *
 * Every work figure is null rather than 0 when no monitored schema models these
 * queues — reporting zero applications for a system with no concept of them is a
 * claim, not a measurement.
 */
export interface ExecutiveSubscribers {
  available: boolean;
  /** False when no monitored schema models these queues — blanks, not zeros. */
  work_available: boolean;
  // Current state; the date toolbar does not move these.
  active: number | null;
  vip: number | null;
  inactive: number | null;
  pullout: number | null;
  // The five field metrics, all driven by the global date toolbar. Each is
  // counted on the status and target-date column its label means — see
  // GowiserReportsDriver::WORK_METRICS.
  /** Every application filed in range, any status, on created_at. */
  application: number | null;
  /** onsite_status Done, on date_installed. */
  installed: number | null;
  /** visit_status Done, on updated_at. */
  repair: number | null;
  /** onsite_status Reschedule, on updated_at. Was called "Schedule". */
  reschedule: number | null;
  /** visit_status In Progress, on updated_at. */
  pending: number | null;
}

/** The metric keys a card can drill into. */
export type ExecutiveMetricKey = 'application' | 'installed' | 'repair' | 'reschedule' | 'pending';

/**
 * One record behind a metric tile.
 *
 * Every field arrives under two names — the reporting vocabulary
 * (`subscriber`, `location`) and SYNC's own (`customer_name`, `area`). The
 * server emits both deliberately; see GowiserReportsDriver::subscriberRow for
 * why. Read them through `cellText()` rather than picking one, so a payload
 * from either vocabulary renders.
 */
export interface MetricRecord {
  id: string;
  account_number?: string;
  subscriber?: string;
  contact_number?: string;
  location?: string;
  plan?: string;
  status?: string;
  /** SYNC aliases for the same values. */
  customer_name?: string;
  account_no?: string;
  contact?: string;
  area?: string;
  plan_name?: string;
  technician: string;
  occurred_at: string | null;
  source?: string;
  source_label?: string;
}

/** One subscriber behind a billing-status counter. Dual-aliased, as above. */
export interface SubscriberRecord {
  id: string;
  account_number?: string;
  subscriber?: string;
  contact_number?: string;
  email?: string;
  location?: string;
  plan?: string;
  raw_status?: string;
  /** SYNC aliases for the same values. */
  customer_name?: string;
  account_no?: string;
  contact?: string;
  area?: string;
  plan_name?: string;
  status?: string;
  date_installed?: string | null;
  vip_expiration?: string | null;
  expires_on?: string | null;
  source?: string;
  source_label?: string;
}

/** A page of drill-down rows, plus the facets its filter dropdowns offer. */
export interface DrillDownPage<T> {
  rows: T[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  plans?: string[];
  areas?: string[];
  answered?: string[];
  failed?: Record<string, string>;
}

/** Active subscribers per plan. Plans carrying nobody are omitted. */
export interface ExecutivePlans {
  available: boolean;
  rows: { label: string; count: number }[];
  total: number;
}

export interface ExecutiveOverviewData {
  as_of: string;
  generated_at: string;
  /** Which pill the global date toolbar is on. */
  timeframe: ExecutiveTimeframe;
  /**
   * `selected` follows the toolbar; `monthly` and `yearly` are fixed
   * comparatives and deliberately do not move — a tile labelled "Monthly"
   * showing a yearly figure would be a lie about what the label means.
   */
  windows: Record<'selected' | 'monthly' | 'yearly', ExecutiveWindow>;
  /** The range section. Follows the toolbar; `windows.selected` names it. */
  daily: ExecutiveDaily;
  monthly: ExecutiveMonthly;
  subscribers: ExecutiveSubscribers;
  plans: ExecutivePlans;
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
