import api from '../config/api';
import { requestCache } from '../utils/requestCache';
import { reportingService } from './reportingService';
import {
  AuditPage,
  ManagedRole,
  ManagedUser,
  PermissionCatalogue,
  UserFormValues,
} from '../types/rbac';

/**
 * The administrative surface: users, roles, the audit trail, and the payables
 * toggle.
 *
 * Deliberately uncached, like databaseService and for the same reason: these are
 * the screens that write, and an administrator who has just saved a permission
 * must see the saved state, not a copy from ten seconds ago.
 *
 * Every one of these endpoints writes to MONITOR's own tables. The monitored
 * databases stay read-only at the connection level regardless — that guarantee
 * is enforced in SourceRegistry::connection() and no request can route around it.
 */

export interface UserManagementPayload {
  users: ManagedUser[];
  roles: ManagedRole[];
  catalogue: PermissionCatalogue;
}

export interface PayableToggle {
  source: string;
  ref: string;
  month: string;
  isPaid: boolean;
  label?: string;
  amount?: number | null;
  paidOn?: string | null;
  reference?: string | null;
  note?: string | null;
}

export const adminService = {
  // ── Users and roles ──────────────────────────────────────────────────

  listUsers: async (): Promise<UserManagementPayload> => {
    const response = await api.get<{ status: string; data: UserManagementPayload }>('/users');

    return response.data.data;
  },

  createUser: async (values: UserFormValues): Promise<ManagedUser> => {
    const response = await api.post<{ status: string; data: { user: ManagedUser } }>(
      '/users',
      values
    );

    return response.data.data.user;
  },

  updateUser: async (id: number, values: UserFormValues): Promise<ManagedUser> => {
    const response = await api.put<{ status: string; data: { user: ManagedUser } }>(
      `/users/${id}`,
      values
    );

    return response.data.data.user;
  },

  deleteUser: async (id: number): Promise<string> => {
    const response = await api.delete<{ status: string; message: string }>(`/users/${id}`);

    return response.data.message;
  },

  /**
   * Reshapes a role's permission map.
   *
   * Affects everyone holding the role, not one account, which is why it needs
   * `action.roles.manage` on top of being able to edit a user.
   */
  updateRole: async (
    id: number,
    permissions: string[],
    description?: string | null
  ): Promise<ManagedRole> => {
    const response = await api.put<{ status: string; data: { role: ManagedRole } }>(
      `/users/roles/${id}`,
      { permissions, description }
    );

    return response.data.data.role;
  },

  // ── Audit trail ──────────────────────────────────────────────────────

  getAuditLog: async (params: {
    page?: number;
    action?: string;
    actor?: string;
    from?: string;
    to?: string;
  }): Promise<AuditPage> => {
    const response = await api.get<{ status: string; data: AuditPage }>('/audit-logs', {
      params: Object.fromEntries(
        Object.entries(params).filter(([, value]) => value !== undefined && value !== '')
      ),
    });

    return response.data.data;
  },

  // ── Payables ─────────────────────────────────────────────────────────

  /**
   * Marks one payable paid or unpaid for a month.
   *
   * Invalidates the financial section afterwards: the ledger, the outstanding
   * total and the executive summary all read this state, and leaving them on a
   * cached copy would show a tick that the totals beside it disagree with.
   */
  togglePayable: async (toggle: PayableToggle): Promise<void> => {
    await api.post('/payables/toggle', {
      source: toggle.source,
      ref: toggle.ref,
      month: toggle.month,
      is_paid: toggle.isPaid,
      label: toggle.label,
      amount: toggle.amount ?? null,
      paid_on: toggle.paidOn ?? null,
      reference: toggle.reference ?? null,
      note: toggle.note ?? null,
    });

    requestCache.invalidatePrefix('reporting_financial');
    requestCache.invalidatePrefix('reporting_executive');
    reportingService.invalidate(toggle.source);
  },
};
