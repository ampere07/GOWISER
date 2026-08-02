import React, { useCallback, useEffect, useState } from 'react';
import {
  CheckCircle2,
  Loader2,
  Pencil,
  Plus,
  ShieldCheck,
  ShieldOff,
  Trash2,
  UserPlus,
  XCircle,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import { Button, ErrorBanner, Pill, Table, TableState, Td, Th, Thead, Tr } from '../components/reporting/primitives';
import { useTheme } from '../hooks/useTheme';
import { AssignableRole, MonitorUser, NewUserPayload, userService } from '../services/userService';
import { UserData } from '../types/api';
import { formatDateTime } from '../utils/format';
import { hasPermission } from '../utils/permissions';

interface UsersProps {
  user: UserData;
  refreshToken: number;
}

const EMPTY_FORM: NewUserPayload = {
  username: '',
  email_address: '',
  password: '',
  first_name: '',
  last_name: '',
  contact_number: '',
  role_id: null,
  active: true,
};

/** Mirrors the server's rule. Stated in the UI so it is not discovered by rejection. */
const MIN_PASSWORD_LENGTH = 12;

/**
 * User administration for the monitoring portal.
 *
 * One of two screens in MONITOR that writes, and it writes to MONITOR's own users table — a
 * MONITOR account grants access to these dashboards and nothing else.
 */
const Users: React.FC<UsersProps> = ({ user, refreshToken }) => {
  const isDarkMode = useTheme();

  const [users, setUsers] = useState<MonitorUser[]>([]);
  const [roles, setRoles] = useState<AssignableRole[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<NewUserPayload>(EMPTY_FORM);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  /** The account being edited, or null when the form is creating a new one. */
  const [editing, setEditing] = useState<MonitorUser | null>(null);
  /** Id awaiting delete confirmation — deleting a user is not undoable from this screen. */
  const [confirmDelete, setConfirmDelete] = useState<number | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const isSuperadmin = user.is_superadmin === true;

  // Creating an account is superadmin-only, stricter than the users.create grant — a new account
  // manufactures access rather than exercising it. The endpoint enforces the same rule, so this
  // only decides whether the button is worth offering.
  const canCreate = isSuperadmin && hasPermission(user.permissions, 'users.create');
  const canEdit = hasPermission(user.permissions, 'users.edit');
  const canDelete = hasPermission(user.permissions, 'users.delete');

  /**
   * Roles this user may assign.
   *
   * Super Admin is offered only to an existing Super Admin. Filtered rather than disabled: an
   * option that cannot be chosen invites the question of how to unlock it, and the answer is
   * "be a Super Admin", which the picker cannot help with.
   */
  const assignableRoles = isSuperadmin ? roles : roles.filter((role) => !role.is_superadmin);

  /** A Super Admin account may only be modified by another Super Admin — as the server enforces. */
  const canModify = (row: MonitorUser) => isSuperadmin || !row.is_superadmin;

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      // Roles are only needed to populate the role picker, so a role-list failure must not stop
      // the user list from rendering — the page is still useful read-only.
      const [userList, roleList] = await Promise.all([
        userService.list(),
        userService.roles().catch(() => [] as AssignableRole[]),
      ]);

      setUsers(userList);
      setRoles(roleList);
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'Could not load users.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load, refreshToken]);

  const openCreate = () => {
    setEditing(null);
    setForm(EMPTY_FORM);
    setFieldErrors({});
    setShowForm(true);
  };

  const openEdit = (row: MonitorUser) => {
    setEditing(row);
    setForm({
      username: row.username,
      email_address: row.email,
      // Blank on purpose. The server treats an empty password as "leave it alone", so the field
      // is a change-it box, never a display of anything.
      password: '',
      first_name: '',
      last_name: '',
      contact_number: row.contact_number ?? '',
      role_id: row.role_id,
      active: row.active,
    });
    setFieldErrors({});
    setShowForm(true);
  };

  const submit = async () => {
    setSaving(true);
    setFieldErrors({});
    setNotice(null);
    setError(null);

    try {
      const payload = {
        ...form,
        // Trimmed here rather than on every keystroke, so a trailing space does not fight the
        // user as they type but never reaches the unique check either.
        username: form.username.trim(),
        email_address: form.email_address.trim(),
        role_id: form.role_id ? Number(form.role_id) : null,
      };

      if (editing) {
        // Password omitted entirely when blank, so the existing hash is untouched.
        const { password, ...rest } = payload;
        const saved = await userService.update(editing.id, password ? payload : rest);
        setNotice(`Updated ${saved.username}.`);
      } else {
        const created = await userService.create(payload);
        setNotice(`Created ${created.username}.`);
      }

      setForm(EMPTY_FORM);
      setEditing(null);
      setShowForm(false);
      await load();
    } catch (err: any) {
      const body = err?.response?.data;
      setFieldErrors(body?.errors ?? {});
      setError(body?.errors ? null : body?.message ?? 'Could not save the user.');
    } finally {
      setSaving(false);
    }
  };

  /**
   * Suspend or restore.
   *
   * The server refuses to suspend your own account or the last one that can manage users, and
   * its message is shown as-is — it knows why better than the button does.
   */
  const toggleActive = async (row: MonitorUser) => {
    setBusyId(row.id);
    setError(null);
    setNotice(null);

    try {
      await userService.setActive(row.id, !row.active);
      setNotice(`${row.username} ${row.active ? 'suspended' : 'restored'}.`);
      await load();
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'Could not change that account.');
    } finally {
      setBusyId(null);
    }
  };

  const remove = async (row: MonitorUser) => {
    setBusyId(row.id);
    setError(null);
    setNotice(null);

    try {
      await userService.remove(row.id);
      setNotice(`Deleted ${row.username}.`);
      setConfirmDelete(null);
      await load();
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'Could not delete that account.');
    } finally {
      setBusyId(null);
    }
  };

  const inputClass = `w-full rounded-md border px-3 py-2 text-sm focus:outline-none ${
    isDarkMode
      ? 'border-gray-700 bg-gray-900 text-gray-100 placeholder-gray-600'
      : 'border-gray-300 bg-white text-gray-900 placeholder-gray-400'
  }`;

  const labelClass = `block text-xs font-medium mb-1 ${
    isDarkMode ? 'text-gray-400' : 'text-gray-600'
  }`;

  const field = (
    name: keyof NewUserPayload,
    label: string,
    type = 'text',
    required = false
  ) => (
    <div>
      <label className={labelClass}>
        {label}
        {required && <span className="text-red-500"> *</span>}
      </label>
      <input
        type={type}
        value={(form[name] as string) ?? ''}
        onChange={(event) => setForm({ ...form, [name]: event.target.value })}
        className={inputClass}
        autoComplete={type === 'password' ? 'new-password' : 'off'}
      />
      {fieldErrors[name === 'email_address' ? 'email_address' : (name as string)]?.map((message) => (
        <p key={message} className="mt-1 text-xs text-red-500">
          {message}
        </p>
      ))}
    </div>
  );

  const selectedRole = roles.find((role) => role.id === Number(form.role_id));

  return (
    <ReportingPage>
      <PageHeader
        title="Users"
        subtitle="Who can sign in to the monitoring portal, and what their role lets them see"
        actions={
          canCreate ? (
            <Button
              variant="primary"
              icon={showForm ? <XCircle size={14} /> : <Plus size={14} />}
              onClick={() => (showForm ? setShowForm(false) : openCreate())}
            >
              {showForm ? 'Cancel' : 'Add User'}
            </Button>
          ) : undefined
        }
      />

      {error && <ErrorBanner message={error} />}

      {notice && (
        <div className="flex items-center gap-2 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-2.5 text-sm text-emerald-500">
          <CheckCircle2 size={16} />
          {notice}
        </div>
      )}

      {showForm && (editing ? canEdit : canCreate) && (
        <Card>
          <CardHeader
            title={editing ? `Edit ${editing.username}` : 'Add User'}
            icon={<UserPlus size={16} />}
          />
          <CardBody>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {field('username', 'Username', 'text', true)}
              {field('email_address', 'Email address', 'email', true)}
              {field('first_name', 'First name')}
              {field('last_name', 'Last name')}
              {field('contact_number', 'Contact number')}

              <div>
                <label className={labelClass}>
                  Password{!editing && <span className="text-red-500"> *</span>}
                </label>
                <input
                  type="password"
                  value={form.password}
                  onChange={(event) => setForm({ ...form, password: event.target.value })}
                  className={inputClass}
                  autoComplete="new-password"
                  placeholder={editing ? 'Leave blank to keep the current password' : ''}
                />
                <p className={`mt-1 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                  {editing
                    ? `Only set this to change it. At least ${MIN_PASSWORD_LENGTH} characters if you do.`
                    : `At least ${MIN_PASSWORD_LENGTH} characters. This account can read the whole company's figures.`}
                </p>
                {fieldErrors.password?.map((message) => (
                  <p key={message} className="mt-1 text-xs text-red-500">
                    {message}
                  </p>
                ))}
              </div>

              <div>
                <label className={labelClass}>Role</label>
                <select
                  value={form.role_id ?? ''}
                  onChange={(event) =>
                    setForm({ ...form, role_id: event.target.value ? Number(event.target.value) : null })
                  }
                  className={inputClass}
                >
                  <option value="">No role — signs in, sees nothing</option>
                  {assignableRoles.map((role) => (
                    <option key={role.id} value={role.id}>
                      {role.name}
                      {role.is_superadmin ? ' — full access' : ''}
                    </option>
                  ))}
                </select>
                {/* Shown before the account exists, because the role is the whole of what this
                    user will be able to reach and it is not editable from here afterwards. */}
                <p className={`mt-1 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                  {!selectedRole
                    ? 'A user with no role is denied every section until one is assigned.'
                    : selectedRole.is_superadmin
                      ? 'Unrestricted — every section, every action, including user administration.'
                      : `Grants ${selectedRole.permissions.length} permission${
                          selectedRole.permissions.length === 1 ? '' : 's'
                        }.`}
                </p>
              </div>
            </div>

            <div className="mt-5 flex items-center gap-3">
              <Button
                variant="primary"
                onClick={submit}
                disabled={
                  saving ||
                  !form.username.trim() ||
                  !form.email_address.trim() ||
                  // On edit a blank password is valid — it means "keep the current one".
                  (editing
                    ? form.password.length > 0 && form.password.length < MIN_PASSWORD_LENGTH
                    : form.password.length < MIN_PASSWORD_LENGTH)
                }
                icon={saving ? <Loader2 size={14} className="animate-spin" /> : <UserPlus size={14} />}
              >
                {saving ? 'Saving…' : editing ? 'Save Changes' : 'Create User'}
              </Button>
            </div>
          </CardBody>
        </Card>
      )}

      <Card flush>
        <CardHeader title="Portal Users" subtitle={`${users.length} account${users.length === 1 ? '' : 's'}`} />
        <Table>
          <Thead>
            <Th>Username</Th>
            <Th>Name</Th>
            <Th>Email</Th>
            <Th>Role</Th>
            <Th>Status</Th>
            <Th>Last login</Th>
            {(canEdit || canDelete) && <Th align="right">Actions</Th>}
          </Thead>
          <tbody>
            <TableState
              colSpan={canEdit || canDelete ? 7 : 6}
              loading={loading && users.length === 0}
              error={error}
              empty={users.length === 0}
              emptyMessage="No users yet."
            />

            {users.map((row) => (
              <Tr key={row.id}>
                <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {row.username}
                </Td>
                <Td>{row.full_name || '—'}</Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>{row.email}</Td>
                <Td>
                  {row.role ? (
                    <Pill tone={row.is_superadmin ? 'warning' : 'info'}>{row.role}</Pill>
                  ) : (
                    <span className="inline-flex items-center gap-1 text-xs text-amber-500">
                      <ShieldOff size={12} /> No role
                    </span>
                  )}
                </Td>
                <Td>
                  <Pill tone={row.active ? 'success' : 'danger'}>
                    {row.active ? 'Active' : 'Suspended'}
                  </Pill>
                </Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {row.last_login ? formatDateTime(row.last_login) : 'Never'}
                </Td>

                {(canEdit || canDelete) && (
                  <Td align="right">
                    {confirmDelete === row.id ? (
                      // Inline rather than a modal: the row being deleted stays visible while
                      // the question is asked, so there is no doubt which account it refers to.
                      <div className="flex items-center justify-end gap-2">
                        <span className="text-xs text-red-500">Delete permanently?</span>
                        <Button
                          variant="danger"
                          onClick={() => remove(row)}
                          disabled={busyId === row.id}
                          icon={busyId === row.id ? <Loader2 size={12} className="animate-spin" /> : undefined}
                        >
                          Delete
                        </Button>
                        <Button variant="outline" onClick={() => setConfirmDelete(null)}>
                          Cancel
                        </Button>
                      </div>
                    ) : (
                      <div className="flex items-center justify-end gap-2">
                        {!canModify(row) && (
                          <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                            Super Admin — managed by a Super Admin
                          </span>
                        )}
                        {canEdit && canModify(row) && (
                          <Button variant="outline" icon={<Pencil size={12} />} onClick={() => openEdit(row)}>
                            Edit
                          </Button>
                        )}
                        {canEdit && canModify(row) && (
                          <Button
                            variant="outline"
                            icon={row.active ? <ShieldOff size={12} /> : <ShieldCheck size={12} />}
                            onClick={() => toggleActive(row)}
                            disabled={busyId === row.id}
                          >
                            {row.active ? 'Suspend' : 'Restore'}
                          </Button>
                        )}
                        {canDelete && canModify(row) && (
                          <Button
                            variant="danger"
                            icon={<Trash2 size={12} />}
                            onClick={() => setConfirmDelete(row.id)}
                          >
                            Delete
                          </Button>
                        )}
                      </div>
                    )}
                  </Td>
                )}
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>
    </ReportingPage>
  );
};

export default Users;
