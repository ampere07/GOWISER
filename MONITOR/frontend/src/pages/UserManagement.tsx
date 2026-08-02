import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  Check,
  KeyRound,
  Loader2,
  Pencil,
  Plus,
  ShieldCheck,
  Trash2,
  UserCog,
  X,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import {
  Button,
  ErrorBanner,
  Pill,
  Table,
  TableState,
  Td,
  Th,
  Thead,
  Tr,
  useControlClass,
} from '../components/reporting/primitives';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import { adminService, UserManagementPayload } from '../services/adminService';
import {
  ManagedRole,
  ManagedUser,
  PermissionOption,
  UserFormValues,
} from '../types/rbac';
import { ACTION } from '../types/rbac';
import { formatDateTime } from '../utils/format';

interface UserManagementProps {
  refreshToken: number;
}

const emptyForm = (roleId: number): UserFormValues => ({
  username: '',
  email_address: '',
  password: '',
  first_name: '',
  last_name: '',
  contact_number: '',
  role_id: roleId,
  active: true,
  grant: [],
  deny: [],
});

/**
 * User Management & Permission Mapping.
 *
 * Two things on one screen because they are one job: assigning someone a role,
 * and — where the role is not quite the right shape — the exception on top of it.
 * Overrides exist so an exception does not have to become a new role; a portal
 * with eleven near-identical roles is one nobody can audit.
 *
 * The effective permission list shown per user is computed server-side and sent
 * back, not derived here. The deny-wins-over-grant rule is exactly the sort of
 * thing two implementations quietly disagree about, and this screen must show
 * what the middleware will actually enforce.
 */
const UserManagement: React.FC<UserManagementProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { can, user: currentUser } = usePermissions();

  const [payload, setPayload] = useState<UserManagementPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  // `form` non-null is what opens the modal, and `editing` non-null is what
  // makes it an edit rather than a create — a third "creating" flag would be a
  // second source of truth for the same thing.
  const [editing, setEditing] = useState<ManagedUser | null>(null);
  const [form, setForm] = useState<UserFormValues | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const [roleEditing, setRoleEditing] = useState<ManagedRole | null>(null);
  const [rolePermissions, setRolePermissions] = useState<string[]>([]);

  const canManageRoles = can(ACTION.rolesManage);

  const load = useCallback(() => {
    setLoading(true);

    adminService
      .listUsers()
      .then((result) => {
        setPayload(result);
        setError(null);
      })
      .catch((err) =>
        setError(err?.response?.data?.message ?? 'Unable to load users and roles.')
      )
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => load(), [load, refreshToken]);

  const allPermissions: PermissionOption[] = useMemo(
    () =>
      payload
        ? [
            ...payload.catalogue.modules,
            ...payload.catalogue.widgets,
            ...payload.catalogue.actions,
          ]
        : [],
    [payload]
  );

  const openCreate = () => {
    setEditing(null);
    setFormError(null);
    setForm(emptyForm(payload?.roles[0]?.id ?? 0));
  };

  const openEdit = (user: ManagedUser) => {
    setEditing(user);
    setFormError(null);
    setForm({
      username: user.username,
      email_address: user.email,
      // Blank means "leave it alone", so a rename need not retype a password.
      password: '',
      first_name: user.first_name ?? '',
      last_name: user.last_name ?? '',
      contact_number: user.contact_number ?? '',
      role_id: user.role_id ?? payload?.roles[0]?.id ?? 0,
      active: user.active,
      grant: user.overrides.grant,
      deny: user.overrides.deny,
    });
  };

  const closeForm = () => {
    setEditing(null);
    setForm(null);
    setFormError(null);
  };

  const submit = async () => {
    if (!form) return;

    setSaving(true);
    setFormError(null);

    try {
      if (editing) {
        await adminService.updateUser(editing.id, form);
      } else {
        await adminService.createUser(form);
      }

      closeForm();
      load();
    } catch (err: any) {
      // Laravel returns field-keyed messages; the first is the one to show —
      // listing all of them on a form this size is noise.
      const errors = err?.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;

      setFormError(first ?? err?.response?.data?.message ?? 'Could not save this user.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (user: ManagedUser) => {
    // Deleting a login is not undoable and the backend refuses the dangerous
    // cases anyway; the confirm is for the merely regrettable ones.
    if (!window.confirm(`Delete the account "${user.username}"? This cannot be undone.`)) {
      return;
    }

    try {
      await adminService.deleteUser(user.id);
      load();
    } catch (err: any) {
      const errors = err?.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;

      setError(first ?? err?.response?.data?.message ?? 'Could not delete this user.');
    }
  };

  const saveRole = async () => {
    if (!roleEditing) return;

    setSaving(true);

    try {
      await adminService.updateRole(roleEditing.id, rolePermissions, roleEditing.description);
      setRoleEditing(null);
      load();
    } catch (err: any) {
      const errors = err?.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;

      setError(first ?? err?.response?.data?.message ?? 'Could not update this role.');
    } finally {
      setSaving(false);
    }
  };

  const controlClass = useControlClass();

  return (
    <ReportingPage>
      <PageHeader
        title="User Management"
        subtitle="Executive roles, and per-user access overrides"
        actions={
          <Button variant="primary" icon={<Plus size={14} />} onClick={openCreate}>
            Add user
          </Button>
        }
      />

      {error && <ErrorBanner message={error} />}

      {/* ── Users ─────────────────────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="Users"
          badge={payload ? `${payload.users.length}` : undefined}
          icon={<UserCog size={16} />}
        />
        <Table>
          <Thead>
            <Th>User</Th>
            <Th>Role</Th>
            <Th>Access</Th>
            <Th>Last login</Th>
            <Th align="right" width="120px" />
          </Thead>
          <tbody>
            <TableState
              colSpan={5}
              loading={loading && !payload}
              error={error}
              empty={(payload?.users.length ?? 0) === 0}
              emptyMessage="No users are configured."
            />

            {(payload?.users ?? []).map((user) => (
              <Tr key={user.id}>
                <Td>
                  <span className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                    {user.full_name || user.username}
                    {currentUser?.id === user.id && (
                      <span className={`ml-1.5 text-xs font-normal ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        (you)
                      </span>
                    )}
                  </span>
                  <span className={`block text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                    {user.username} · {user.email}
                  </span>
                </Td>
                <Td>
                  <Pill tone="info">{user.role}</Pill>
                </Td>
                <Td>
                  <span className="flex flex-wrap items-center gap-1">
                    <Pill tone={user.active ? 'success' : 'neutral'}>
                      {user.active ? 'Active' : 'Suspended'}
                    </Pill>
                    {/* Flagged rather than expanded: the point of the marker is
                        that this account does not match its role, which is the
                        thing an auditor scans for. */}
                    {user.has_overrides && (
                      <Pill tone="warning">
                        {user.overrides.grant.length > 0 && `+${user.overrides.grant.length}`}
                        {user.overrides.grant.length > 0 && user.overrides.deny.length > 0 && ' '}
                        {user.overrides.deny.length > 0 && `−${user.overrides.deny.length}`} custom
                      </Pill>
                    )}
                    <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                      {user.permissions.length} permissions
                    </span>
                  </span>
                </Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {user.last_login ? formatDateTime(user.last_login) : 'never'}
                </Td>
                <Td align="right">
                  <span className="inline-flex gap-1">
                    <button
                      type="button"
                      onClick={() => openEdit(user)}
                      title="Edit user"
                      className={`rounded-lg border p-1.5 transition-colors ${
                        isDarkMode
                          ? 'border-gray-700 text-gray-300 hover:bg-gray-800'
                          : 'border-gray-300 text-gray-600 hover:bg-gray-50'
                      }`}
                    >
                      <Pencil size={13} />
                    </button>
                    <button
                      type="button"
                      onClick={() => remove(user)}
                      disabled={currentUser?.id === user.id}
                      title={
                        currentUser?.id === user.id
                          ? 'You cannot delete your own account'
                          : 'Delete user'
                      }
                      className="rounded-lg border border-red-500/40 text-red-500 p-1.5 transition-colors hover:bg-red-500/10 disabled:opacity-30 disabled:cursor-not-allowed"
                    >
                      <Trash2 size={13} />
                    </button>
                  </span>
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>

      {/* ── Roles ─────────────────────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="Roles & Permission Map"
          subtitle={
            canManageRoles
              ? 'Reshaping a role changes what everyone holding it can see'
              : 'Read-only — your role cannot edit permission maps'
          }
          icon={<ShieldCheck size={16} />}
        />
        <Table>
          <Thead>
            <Th>Role</Th>
            <Th>Description</Th>
            <Th align="right">Users</Th>
            <Th align="right">Permissions</Th>
            <Th align="right" width="90px" />
          </Thead>
          <tbody>
            <TableState
              colSpan={5}
              loading={loading && !payload}
              empty={(payload?.roles.length ?? 0) === 0}
              emptyMessage="No roles are configured."
            />

            {(payload?.roles ?? []).map((role) => (
              <Tr key={role.id}>
                <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {role.name}
                  {role.is_system && (
                    <Pill tone="neutral" className="ml-2">
                      system
                    </Pill>
                  )}
                </Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {role.description || '—'}
                </Td>
                <Td align="right" className="tabular-nums">
                  {role.user_count ?? 0}
                </Td>
                <Td align="right" className="tabular-nums">
                  {role.permissions.length}
                </Td>
                <Td align="right">
                  <button
                    type="button"
                    disabled={!canManageRoles}
                    onClick={() => {
                      setRoleEditing(role);
                      setRolePermissions(role.permissions);
                    }}
                    title={
                      canManageRoles ? 'Edit permission map' : 'Your role cannot edit permission maps'
                    }
                    className={`rounded-lg border p-1.5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed ${
                      isDarkMode
                        ? 'border-gray-700 text-gray-300 hover:bg-gray-800'
                        : 'border-gray-300 text-gray-600 hover:bg-gray-50'
                    }`}
                  >
                    <KeyRound size={13} />
                  </button>
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>

      {/* ── User form ─────────────────────────────────────────────────── */}
      {form && (
        <Modal
          title={editing ? `Edit ${editing.username}` : 'Add user'}
          onClose={closeForm}
          onSubmit={submit}
          saving={saving}
          error={formError}
        >
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Field label="Username">
              <input
                className={controlClass}
                value={form.username}
                onChange={(event) => setForm({ ...form, username: event.target.value })}
              />
            </Field>
            <Field label="Email">
              <input
                type="email"
                className={controlClass}
                value={form.email_address}
                onChange={(event) => setForm({ ...form, email_address: event.target.value })}
              />
            </Field>
            <Field label="First name">
              <input
                className={controlClass}
                value={form.first_name}
                onChange={(event) => setForm({ ...form, first_name: event.target.value })}
              />
            </Field>
            <Field label="Last name">
              <input
                className={controlClass}
                value={form.last_name}
                onChange={(event) => setForm({ ...form, last_name: event.target.value })}
              />
            </Field>
            <Field
              label={editing ? 'Password (leave blank to keep)' : 'Password'}
              hint="At least 10 characters"
            >
              <input
                type="password"
                autoComplete="new-password"
                className={controlClass}
                value={form.password}
                onChange={(event) => setForm({ ...form, password: event.target.value })}
              />
            </Field>
            <Field label="Role">
              <select
                className={controlClass}
                value={form.role_id}
                onChange={(event) => setForm({ ...form, role_id: Number(event.target.value) })}
              >
                {(payload?.roles ?? []).map((role) => (
                  <option key={role.id} value={role.id}>
                    {role.name}
                  </option>
                ))}
              </select>
            </Field>
          </div>

          <label className="flex items-center gap-2 mt-3 text-sm">
            <input
              type="checkbox"
              checked={form.active}
              onChange={(event) => setForm({ ...form, active: event.target.checked })}
            />
            Account is active
          </label>

          {/* ── Overrides ──────────────────────────────────────────── */}
          <div className={`mt-4 pt-4 border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}>
            <p className={`text-sm font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
              Access overrides
            </p>
            <p className={`text-xs mt-0.5 mb-3 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
              Exceptions on top of the role. <strong>Deny wins</strong> over both a grant and the
              role, so an override can restrict as well as extend.
            </p>

            <PermissionPicker
              options={allPermissions}
              grant={form.grant}
              deny={form.deny}
              onChange={(grant, deny) => setForm({ ...form, grant, deny })}
              rolePermissions={
                payload?.roles.find((role) => role.id === form.role_id)?.permissions ?? []
              }
            />
          </div>
        </Modal>
      )}

      {/* ── Role permission map ───────────────────────────────────────── */}
      {roleEditing && (
        <Modal
          title={`Permission map — ${roleEditing.name}`}
          onClose={() => setRoleEditing(null)}
          onSubmit={saveRole}
          saving={saving}
          error={null}
        >
          <p className={`text-xs mb-3 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            Applies to every user holding this role, including{' '}
            {roleEditing.user_count ?? 0} account{(roleEditing.user_count ?? 0) === 1 ? '' : 's'}{' '}
            right now.
          </p>

          {payload &&
            (['modules', 'widgets', 'actions'] as const).map((group) => (
              <div key={group} className="mb-4">
                <p
                  className={`text-xs font-semibold uppercase tracking-wide mb-2 ${
                    isDarkMode ? 'text-gray-400' : 'text-gray-500'
                  }`}
                >
                  {group}
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                  {payload.catalogue[group].map((option) => (
                    <label key={option.id} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={rolePermissions.includes(option.id)}
                        onChange={(event) =>
                          setRolePermissions((current) =>
                            event.target.checked
                              ? [...current, option.id]
                              : current.filter((id) => id !== option.id)
                          )
                        }
                      />
                      <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                        {option.label}
                      </span>
                    </label>
                  ))}
                </div>
              </div>
            ))}
        </Modal>
      )}
    </ReportingPage>
  );
};

/**
 * Three-state permission control: inherited, granted, denied.
 *
 * Deliberately not two checkboxes. "Granted" and "denied" are not opposites here
 * — the meaningful third state is *neither*, meaning the role decides — and a
 * pair of checkboxes makes that state look like a mistake rather than the
 * default it is.
 */
const PermissionPicker: React.FC<{
  options: PermissionOption[];
  grant: string[];
  deny: string[];
  rolePermissions: string[];
  onChange: (grant: string[], deny: string[]) => void;
}> = ({ options, grant, deny, rolePermissions, onChange }) => {
  const isDarkMode = useTheme();

  const set = (id: string, state: 'inherit' | 'grant' | 'deny') => {
    const nextGrant = grant.filter((item) => item !== id);
    const nextDeny = deny.filter((item) => item !== id);

    if (state === 'grant') nextGrant.push(id);
    if (state === 'deny') nextDeny.push(id);

    onChange(nextGrant, nextDeny);
  };

  return (
    <div className="max-h-64 overflow-y-auto pr-1 space-y-1">
      {options.map((option) => {
        const state = deny.includes(option.id)
          ? 'deny'
          : grant.includes(option.id)
          ? 'grant'
          : 'inherit';

        const fromRole = rolePermissions.includes(option.id);

        return (
          <div
            key={option.id}
            className={`flex items-center justify-between gap-2 rounded-lg px-2 py-1 ${
              isDarkMode ? 'hover:bg-gray-800/60' : 'hover:bg-gray-50'
            }`}
          >
            <span className={`text-sm truncate ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
              {option.label}
              {/* Says what "inherit" would resolve to, so the default state is
                  legible instead of a shrug. */}
              <span className={`ml-1.5 text-[11px] ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
                {fromRole ? 'role: allowed' : 'role: denied'}
              </span>
            </span>

            <span className="inline-flex rounded-lg border overflow-hidden flex-shrink-0 border-gray-300 dark:border-gray-700">
              {(['inherit', 'grant', 'deny'] as const).map((value, index) => (
                <button
                  key={value}
                  type="button"
                  aria-pressed={state === value}
                  onClick={() => set(option.id, value)}
                  className={`px-2 py-0.5 text-[11px] font-semibold transition-colors ${
                    index > 0 ? 'border-l border-gray-300 dark:border-gray-700' : ''
                  } ${
                    state === value
                      ? value === 'deny'
                        ? 'bg-red-500 text-white'
                        : value === 'grant'
                        ? 'bg-emerald-600 text-white'
                        : isDarkMode
                        ? 'bg-gray-200 text-gray-900'
                        : 'bg-gray-700 text-white'
                      : isDarkMode
                      ? 'bg-gray-900 text-gray-400 hover:bg-gray-800'
                      : 'bg-white text-gray-600 hover:bg-gray-50'
                  }`}
                >
                  {value === 'inherit' ? 'Role' : value === 'grant' ? 'Allow' : 'Deny'}
                </button>
              ))}
            </span>
          </div>
        );
      })}
    </div>
  );
};

const Field: React.FC<{ label: string; hint?: string; children: React.ReactNode }> = ({
  label,
  hint,
  children,
}) => {
  const isDarkMode = useTheme();

  return (
    <label className="block">
      <span className={`block text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        {label}
      </span>
      {children}
      {hint && (
        <span className={`block text-[11px] mt-0.5 ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
          {hint}
        </span>
      )}
    </label>
  );
};

const Modal: React.FC<{
  title: string;
  onClose: () => void;
  onSubmit: () => void;
  saving: boolean;
  error: string | null;
  children: React.ReactNode;
}> = ({ title, onClose, onSubmit, saving, error, children }) => {
  const isDarkMode = useTheme();

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div
        className={`w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl border ${
          isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'
        }`}
      >
        <div
          className={`flex items-center justify-between gap-3 px-5 py-3.5 border-b ${
            isDarkMode ? 'border-gray-800' : 'border-gray-200'
          }`}
        >
          <h3 className={`font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>{title}</h3>
          <button
            type="button"
            onClick={onClose}
            className={isDarkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-black'}
          >
            <X size={18} />
          </button>
        </div>

        <CardBody>
          {error && (
            <div className="mb-3 p-2.5 rounded-lg bg-red-500/10 border border-red-500/20 text-red-500 flex items-start gap-2 text-sm">
              <AlertTriangle size={15} className="mt-0.5 flex-shrink-0" />
              {error}
            </div>
          )}

          {children}
        </CardBody>

        <div
          className={`flex justify-end gap-2 px-5 py-3 border-t ${
            isDarkMode ? 'border-gray-800' : 'border-gray-200'
          }`}
        >
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="primary"
            onClick={onSubmit}
            disabled={saving}
            icon={saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
          >
            Save
          </Button>
        </div>
      </div>
    </div>
  );
};

export default UserManagement;
