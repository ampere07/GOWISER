/**
 * The permission vocabulary, mirroring App\Support\Permissions.
 *
 * Duplicated across the wire on purpose. The backend is the authority — it gates
 * every route and masks every payload — and this file exists only so the UI can
 * hide a control the server would reject anyway. A control that appears and then
 * 403s on click is worse than one that was never drawn.
 *
 * Keep the ids identical to the PHP constants. A typo here fails closed (the
 * permission is never held, so the control stays hidden), which is the right
 * direction for a mistake to fall.
 */

// ── Modules (tabs) ─────────────────────────────────────────────────────

export const MODULE = {
  executive: 'executive-overview',
  subscribers: 'subscriber-analytics',
  financial: 'financial',
  operations: 'field-operations',
  tech: 'tech',
  employee: 'employee',
  users: 'users',
  roles: 'roles',
  audit: 'audit',
  settings: 'settings',
  databases: 'databases',
} as const;

// ── Widgets ────────────────────────────────────────────────────────────

export const WIDGET = {
  financialRevenue: 'widget.financial.revenue',
  financialChannels: 'widget.financial.channels',
  financialMetrics: 'widget.financial.metrics',
  financialOpex: 'widget.financial.opex-capex',
  financialPayables: 'widget.financial.payables',
  subscriberBilling: 'widget.subscriber.billing',
  subscriberBarangay: 'widget.subscriber.barangay',
  operationsSla: 'widget.operations.sla',
  executiveFinance: 'widget.executive.finance',
} as const;

// ── Actions ────────────────────────────────────────────────────────────

export const ACTION = {
  payableToggle: 'action.payables.toggle',
  financialExport: 'action.financial.export',
  filtersModify: 'action.filters.modify',
  usersManage: 'action.users.manage',
  rolesManage: 'action.roles.manage',
  auditView: 'action.audit.view',
  settingsManage: 'action.settings.manage',
} as const;

export type PermissionId =
  | (typeof MODULE)[keyof typeof MODULE]
  | (typeof WIDGET)[keyof typeof WIDGET]
  | (typeof ACTION)[keyof typeof ACTION];

/** One selectable permission, as the mapping screen renders it. */
export interface PermissionOption {
  id: string;
  label: string;
}

export interface PermissionCatalogue {
  modules: PermissionOption[];
  widgets: PermissionOption[];
  actions: PermissionOption[];
}

/** Per-user exceptions on top of the role. Deny wins over grant, and over the role. */
export interface PermissionOverrides {
  grant: string[];
  deny: string[];
}

export interface ManagedRole {
  id: number;
  name: string;
  description: string | null;
  permissions: string[];
  is_system: boolean;
  user_count?: number;
}

export interface ManagedUser {
  id: number;
  username: string;
  email: string;
  full_name: string;
  first_name: string | null;
  last_name: string | null;
  contact_number: string | null;
  role_id: number | null;
  role: string;
  active: boolean;
  last_login: string | null;
  overrides: PermissionOverrides;
  has_overrides: boolean;
  /** What the middleware will actually enforce, deny rule already applied. */
  permissions: string[];
}

export interface UserFormValues {
  username: string;
  email_address: string;
  password: string;
  first_name: string;
  last_name: string;
  contact_number: string;
  role_id: number;
  active: boolean;
  grant: string[];
  deny: string[];
}

// ── Audit trail ────────────────────────────────────────────────────────

export interface AuditEntry {
  id: number;
  actor: string;
  action: string;
  subject_type: string | null;
  subject_id: string | null;
  description: string | null;
  changes: Record<string, { from: unknown; to: unknown }> | null;
  ip_address: string | null;
  logged_at: string | null;
}

export interface AuditPage {
  rows: AuditEntry[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  actions: string[];
}
