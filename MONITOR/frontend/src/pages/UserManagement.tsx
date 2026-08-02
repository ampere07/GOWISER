import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  Check,
  Loader2,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  User,
  X,
} from 'lucide-react';
import { ReportingPage } from '../components/reporting/PageLayout';
import Card, { CardBody } from '../components/reporting/Card';
import { Button, ErrorBanner, Pill, useControlClass } from '../components/reporting/primitives';
import { usePalette } from '../hooks/usePalette';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import { adminService, UserManagementPayload } from '../services/adminService';
import { ManagedUser, PermissionOption, UserFormValues } from '../types/rbac';
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
 * User Management.
 *
 * Assigns a role, and — where the role is not quite the right shape — the
 * exception on top of it. Overrides exist so an exception does not have to
 * become a new role; a portal with eleven near-identical roles is one nobody can
 * audit.
 *
 * Reshaping what a role *means* lives on the Roles screen instead: it affects
 * everyone holding it at once, and that blast radius should not be one click
 * away from editing a single account.
 *
 * The effective permission list shown per user is computed server-side and sent
 * back, not derived here. The deny-wins-over-grant rule is exactly the sort of
 * thing two implementations quietly disagree about, and this screen must show
 * what the middleware will actually enforce.
 */
const UserManagement: React.FC<UserManagementProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const palette = usePalette();
  const { user: currentUser } = usePermissions();

  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState('all');

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

  /**
   * The rows actually shown.
   *
   * Filtered client-side: the whole list is already in the payload, and a round
   * trip to narrow a list of accounts already in memory would be slower and
   * could briefly disagree with what is on screen.
   */
  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase();
    const users = payload?.users ?? [];

    return users.filter((user) => {
      if (needle) {
        const haystack = `${user.full_name} ${user.username} ${user.email}`.toLowerCase();

        if (!haystack.includes(needle)) return false;
      }

      if (filter === 'active') return user.active;
      if (filter === 'suspended') return !user.active;
      if (filter === 'overrides') return user.has_overrides;
      if (filter.startsWith('role:')) return user.role_id === Number(filter.slice(5));

      return true;
    });
  }, [payload, search, filter]);

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

  const controlClass = useControlClass();

  return (
    <ReportingPage>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-2xl sm:text-3xl font-bold tracking-tight">User Management</h1>
          <p className={`text-sm mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            Manage system users and permissions
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={load}
            title="Reload users"
            className={`rounded-lg border p-2 transition-colors ${
              isDarkMode
                ? 'border-gray-700 text-gray-300 hover:bg-gray-800'
                : 'border-gray-300 text-gray-600 hover:bg-gray-50'
            }`}
          >
            <RefreshCw size={16} className={loading ? 'animate-spin' : ''} />
          </button>

          <button
            type="button"
            onClick={openCreate}
            title="Add user"
            // Brand colour rather than the generic primary: this is the one
            // create action on the screen, and the palette exists to mark it.
            className="rounded-lg p-2.5 text-white transition-opacity hover:opacity-90"
            style={{ backgroundColor: palette.primary }}
          >
            <Plus size={18} />
          </button>
        </div>
      </div>

      {error && <ErrorBanner message={error} />}

      {/* ── Search and filter ─────────────────────────────────────────── */}
      <div className="flex flex-wrap items-center gap-3">
        <span className="relative flex-1 min-w-[220px]">
          <Search
            size={16}
            className={`absolute left-3 top-1/2 -translate-y-1/2 ${
              isDarkMode ? 'text-gray-500' : 'text-gray-400'
            }`}
          />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search name, username, email…"
            className={`${controlClass} w-full !pl-10 !py-2.5`}
            aria-label="Search users"
          />
        </span>

        <select
          value={filter}
          onChange={(event) => setFilter(event.target.value)}
          className={`${controlClass} !py-2.5 min-w-[160px]`}
          aria-label="Filter users"
        >
          <option value="all">All Users</option>
          <option value="active">Active only</option>
          <option value="suspended">Suspended only</option>
          <option value="overrides">With custom access</option>
          {(payload?.roles ?? []).map((role) => (
            <option key={role.id} value={`role:${role.id}`}>
              {role.name}
            </option>
          ))}
        </select>
      </div>

      {/* ── Users ─────────────────────────────────────────────────────── */}
      <Card flush>
        {loading && !payload ? (
          <p
            className={`flex items-center justify-center gap-2 py-16 text-sm ${
              isDarkMode ? 'text-gray-400' : 'text-gray-500'
            }`}
          >
            <Loader2 size={15} className="animate-spin" />
            Loading…
          </p>
        ) : visible.length === 0 ? (
          <p className={`py-16 text-center text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            {search.trim() || filter !== 'all'
              ? 'No user matches these filters.'
              : 'No users are configured.'}
          </p>
        ) : (
          <div>
            {visible.map((user, index) => (
              <div
                key={user.id}
                // The whole row opens the editor. The row is the primary target;
                // a pencil at the far right of a wide list is not.
                role="button"
                tabIndex={0}
                onClick={() => openEdit(user)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openEdit(user);
                  }
                }}
                className={`flex items-center gap-4 px-4 sm:px-5 py-3.5 cursor-pointer transition-colors ${
                  index > 0
                    ? isDarkMode
                      ? 'border-t border-gray-800'
                      : 'border-t border-gray-100'
                    : ''
                } ${isDarkMode ? 'hover:bg-gray-800/60' : 'hover:bg-gray-50'}`}
              >
                <span
                  className={`flex-shrink-0 w-11 h-11 rounded-full flex items-center justify-center ${
                    isDarkMode ? 'bg-gray-800 text-gray-400' : 'bg-gray-100 text-gray-500'
                  }`}
                >
                  <User size={20} />
                </span>

                <span className="min-w-0 flex-1">
                  <span
                    className={`block font-semibold truncate ${
                      isDarkMode ? 'text-white' : 'text-gray-900'
                    }`}
                  >
                    {user.full_name || user.username}
                    {currentUser?.id === user.id && (
                      <span
                        className={`ml-1.5 text-xs font-normal ${
                          isDarkMode ? 'text-gray-500' : 'text-gray-400'
                        }`}
                      >
                        (you)
                      </span>
                    )}
                  </span>
                  <span
                    className={`block text-sm truncate ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}
                  >
                    {user.username} • {user.email}
                  </span>
                  <span className="flex flex-wrap items-center gap-1.5 mt-1">
                    {!user.active && <Pill tone="neutral">Suspended</Pill>}
                    {/* Flagged rather than expanded: the point of the marker is
                        that this account does not match its role, which is what
                        an auditor scans a list like this for. */}
                    {user.has_overrides && (
                      <Pill tone="warning">
                        {user.overrides.grant.length > 0 && `+${user.overrides.grant.length}`}
                        {user.overrides.grant.length > 0 && user.overrides.deny.length > 0 && ' '}
                        {user.overrides.deny.length > 0 && `-${user.overrides.deny.length}`} custom
                      </Pill>
                    )}
                    <span className={`text-xs ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
                      {user.permissions.length} permissions ·{' '}
                      {user.last_login ? formatDateTime(user.last_login) : 'never signed in'}
                    </span>
                  </span>
                </span>

                <span className="flex items-center gap-2 flex-shrink-0">
                  <span
                    className={`hidden sm:inline text-[11px] font-bold uppercase tracking-wide px-2.5 py-1 rounded ${
                      isDarkMode ? 'bg-gray-800 text-gray-300' : 'bg-gray-100 text-gray-600'
                    }`}
                  >
                    {user.role}
                  </span>

                  <button
                    type="button"
                    onClick={(event) => {
                      // The row is a click target too, so this has to stop the
                      // event or deleting would also open the editor behind it.
                      event.stopPropagation();
                      remove(user);
                    }}
                    disabled={currentUser?.id === user.id}
                    title={
                      currentUser?.id === user.id
                        ? 'You cannot delete your own account'
                        : 'Delete user'
                    }
                    className="rounded-lg border border-red-500/40 text-red-500 p-1.5 transition-colors hover:bg-red-500/10 disabled:opacity-30 disabled:cursor-not-allowed"
                  >
                    <Trash2 size={14} />
                  </button>
                </span>
              </div>
            ))}
          </div>
        )}
      </Card>

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Showing {visible.length} of {payload?.users.length ?? 0} accounts. What a role *means* is
        edited on Roles Management; the overrides here are per-person exceptions on top of it.
      </p>

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
