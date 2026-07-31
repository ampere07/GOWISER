/** Sections a source's schema can actually answer. */
export type Capability = 'overview' | 'operations' | 'revenue' | 'financials';

export interface MonitorSource {
  key: string;
  label: string;
  capabilities: Capability[];
}

export interface Branch {
  id: string;
  label: string;
  location: string | null;
}

export type FinancialPeriod = 'daily' | 'weekly' | 'monthly' | 'yearly';

export interface FinancialKpi {
  income: number;
  income_count: number;
  office_income: number;
  office_count: number;
  portal_income: number;
  portal_count: number;
  expenses: number;
  expenses_count: number;
  net: number;
  margin_pct: number | null;
}

export interface FinancialPoint {
  period: string;
  label: string;
  income: number;
  expenses: number;
  net: number;
}

export interface FinancialSlice {
  label: string;
  total: number;
  count: number;
}

export interface BranchResult {
  id: string;
  label: string;
  income: number;
  expenses: number;
  net: number;
  subscribers: number;
}

export interface PlanSlice {
  label: string;
  count: number;
}

export interface Financials {
  period: FinancialPeriod;
  period_label: string;
  range: { from: string; to: string };
  branch: string | null;
  branch_label: string;
  as_of: string;
  kpi: FinancialKpi;
  series: FinancialPoint[];
  by_method: FinancialSlice[];
  by_payment_type: FinancialSlice[];
  by_expense_type: FinancialSlice[];
  by_branch: BranchResult[];
  plans: PlanSlice[];
  subscribers: {
    total: number;
    active: number;
    expired: number;
    suspended: number;
  };
}

export interface SessionBreakdown {
  online: number;
  offline: number;
  disconnected: number;
  restricted: number;
}

export interface Overview {
  total_accounts: number;
  sessions: SessionBreakdown;
  receivables: number;
  accounts_in_arrears: number;
  revenue_mtd: number;
  revenue_today: number;
  applications_mtd: number;
  installs_mtd: number;
  period: {
    month_start: string;
    as_of: string;
  };
}

export interface StatusGroup {
  [status: string]: number;
}

export interface LabelledCount {
  label: string;
  count: number;
}

export interface Operations {
  support_status_today: StatusGroup;
  visit_status_today: StatusGroup;
  job_order_status_today: StatusGroup;
  application_status_today: StatusGroup;
  backlog: {
    applications_pending: number;
    job_orders_in_progress: number;
    service_orders_open: number;
  };
  monthly_support_concerns: LabelledCount[];
  monthly_repair_categories: LabelledCount[];
}

export interface RevenuePoint {
  period: string;
  total: number;
  transactions: number;
}

export interface RevenueSlice {
  label: string;
  total: number;
  count?: number;
}

export interface Revenue {
  monthly: RevenuePoint[];
  mtd_by_method: RevenueSlice[];
  mtd_by_type: RevenueSlice[];
}

export interface ConsolidatedRow {
  source: string;
  label: string;
  reachable: boolean;
  error?: string;
  overview: Overview | null;
}

export interface Consolidated {
  sources: ConsolidatedRow[];
  totals: {
    total_accounts: number;
    online: number;
    receivables: number;
    revenue_mtd: number;
    applications_mtd: number;
  };
}

/** Every response from /api/monitor/* carries which source answered it. */
export interface SourcedResponse<T> {
  status: string;
  source: string;
  source_label: string;
  data: T;
}
