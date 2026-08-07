import React, { useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  Check,
  KeyRound,
  Loader2,
  Lock,
  RefreshCw,
  ShieldCheck,
  Users,
  X,
} from 'lucide-react';
import { ReportingPage } from '../components/reporting/PageLayout';
import Card, { CardBody } from '../components/reporting/Card';
import { Button, ErrorBanner, Pill } from '../components/reporting/primitives';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import { adminService, UserManagementPayload } from '../services/adminService';
import { ACTION, ManagedRole, PermissionOption } from '../types/rbac';

interface RolesProps {
  refreshToken: number;
}

const GROUPS: { key: 'modules' | 'widgets' | 'actions'; label: string; hint: string }[] = [
  {
    key: 'modules',
    label: 'Modules',
    hint: 'Which tabs appear. The endpoint behind a tab is refused too, so hiding one is not cosmetic.',
  },
  {
    key: 'widgets',
    label: 'Widgets',
    hint: 'Which panels render. Figures behind a withheld panel are stripped server-side, not just hidden.',
  },
  {
    key: 'actions',
    label: 'Actions',
    hint: 'What may be changed or taken out of the building — settlements, exports, user access.',
  },
];

/**
 * Roles & Permissions.
 *
 * Split from User Management because they are different jobs at different
 * cadences: assigning someone a role happens whenever a person joins, while
 * reshaping what a role means happens rarely and affects everyone holding it at
 * once. Putting the second behind its own screen — and its own permission —
 * makes that blast radius visible rather than one click away from a user edit.
 */
const Roles: React.FC<RolesProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { can } = usePermissions();

  const [payload, setPayload] = useState<UserManagementPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const [editing, setEditing] = useState<ManagedRole | null>(null);
  const [selected, setSelected] = useState<string[]>([]);

  const editable = can(ACTION.rolesManage);

  const load = useCallback(() => {
    setLoading(true);

    adminService
      .listUsers()
      .then((result) => {
        setPayload(result);
        setError(null);
      })
      .catch((err) => setError(err?.response?.data?.message ?? 'Unable to load roles.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => load(), [load, refreshToken]);

  const save = async () => {
    if (!editing) return;

    setSaving(true);

    try {
      await adminService.updateRole(editing.id, selected, editing.description);
      setEditing(null);
      load();
    } catch (err: any) {
      const errors = err?.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;

      setError(first ?? err?.response?.data?.message ?? 'Could not update this role.');
    } finally {
      setSaving(false);
    }
  };

  /** Counts a role's grants within one tier, so the table reads at a glance. */
  const countIn = (role: ManagedRole, options: PermissionOption[]): number =>
    options.filter((option) => role.permissions.includes(option.id)).length;

  return (
    <ReportingPage>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-2xl sm:text-3xl font-bold tracking-tight">Roles Management</h1>
          <p className={`text-sm mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            {editable
              ? 'Reshaping a role changes what everyone holding it can see'
              : 'Read-only — your role cannot edit permission maps'}
          </p>
        </div>

        <button
          type="button"
          onClick={load}
          title="Reload roles"
          className={`rounded-lg border p-2 transition-colors ${
            isDarkMode
              ? 'border-gray-700 text-gray-300 hover:bg-gray-800'
              : 'border-gray-300 text-gray-600 hover:bg-gray-50'
          }`}
        >
          <RefreshCw size={16} className={loading ? 'animate-spin' : ''} />
        </button>
      </div>

      {error && <ErrorBanner message={error} />}

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
        ) : (payload?.roles.length ?? 0) === 0 ? (
          <p className={`py-16 text-center text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            No roles are configured.
          </p>
        ) : (
          <div>
            {(payload?.roles ?? []).map((role, index) => {
              // A full-access role's map is not consulted when access is
              // decided, so there is nothing here to edit — the server refuses
              // the save. Offering the editor anyway would be a form that
              // accepts input and then rejects it, which is a worse way to say
              // "this cannot be changed" than not opening.
              const rowEditable = editable && !role.full_access;

              return (
              <div
                key={role.id}
                role={rowEditable ? 'button' : undefined}
                tabIndex={rowEditable ? 0 : undefined}
                onClick={() => {
                  if (!rowEditable) return;

                  setEditing(role);
                  setSelected(role.permissions);
                }}
                onKeyDown={(event) => {
                  if (!rowEditable) return;

                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    setEditing(role);
                    setSelected(role.permissions);
                  }
                }}
                className={`flex items-center gap-4 px-4 sm:px-5 py-3.5 transition-colors ${
                  rowEditable ? 'cursor-pointer' : ''
                } ${
                  index > 0
                    ? isDarkMode
                      ? 'border-t border-gray-800'
                      : 'border-t border-gray-100'
                    : ''
                } ${rowEditable ? (isDarkMode ? 'hover:bg-gray-800/60' : 'hover:bg-gray-50') : ''}`}
              >
                <span
                  className={`flex-shrink-0 w-11 h-11 rounded-full flex items-center justify-center ${
                    isDarkMode ? 'bg-gray-800 text-gray-400' : 'bg-gray-100 text-gray-500'
                  }`}
                >
                  <ShieldCheck size={20} />
                </span>

                <span className="min-w-0 flex-1">
                  <span
                    className={`block font-semibold truncate ${
                      isDarkMode ? 'text-white' : 'text-gray-900'
                    }`}
                  >
                    {role.name}
                    {role.is_system && (
                      <Pill tone="neutral" className="ml-2">
                        system
                      </Pill>
                    )}
                    {/* Stated rather than left to be inferred from three full
                        counters, which would read as a role somebody happened to
                        tick every box on rather than one that holds everything by
                        definition — including permissions this build has not
                        shipped yet. */}
                    {role.full_access && (
                      <Pill tone="info" className="ml-2">
                        full access
                      </Pill>
                    )}
                  </span>
                  <span
                    className={`block text-sm truncate ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}
                  >
                    {role.description || 'No description'}
                  </span>

                  {/* The three tiers side by side rather than one total: "18
                      permissions" says nothing about whether this role can
                      *act*, which is the question being asked of the number. */}
                  {payload && (
                    <span className="flex flex-wrap items-center gap-3 mt-1">
                      {GROUPS.map((group) => (
                        <span
                          key={group.key}
                          className={`text-xs ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}
                          title={group.hint}
                        >
                          {group.label}{' '}
                          <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>
                            {countIn(role, payload.catalogue[group.key])}
                          </span>
                          /{payload.catalogue[group.key].length}
                        </span>
                      ))}
                    </span>
                  )}
                </span>

                <span className="flex items-center gap-2 flex-shrink-0">
                  <span
                    className={`hidden sm:inline-flex items-center gap-1 text-[11px] font-bold uppercase tracking-wide px-2.5 py-1 rounded ${
                      isDarkMode ? 'bg-gray-800 text-gray-300' : 'bg-gray-100 text-gray-600'
                    }`}
                  >
                    <Users size={11} />
                    {role.user_count ?? 0}
                  </span>

                  <span
                    title={
                      role.full_access
                        ? 'This role holds every permission by definition and cannot be narrowed.'
                        : undefined
                    }
                    className={`rounded-lg border p-1.5 ${
                      isDarkMode ? 'border-gray-700 text-gray-400' : 'border-gray-300 text-gray-500'
                    } ${rowEditable ? '' : 'opacity-40'}`}
                  >
                    {rowEditable ? <KeyRound size={14} /> : <Lock size={14} />}
                  </span>
                </span>
              </div>
              );
            })}
          </div>
        )}
      </Card>

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Permissions fail closed: an id a role does not hold is denied, and a permission added in a
        future release is invisible to every existing role until it is granted here. Where one person
        needs an exception, use a per-user override on the User Management screen rather than
        creating a near-duplicate role. <strong>Super Admin</strong> and <strong>Executive</strong>{' '}
        are the two exceptions — they hold everything, including permissions a later release adds, so
        their maps are shown complete and are not editable.
      </p>

      {/* ── Permission map ────────────────────────────────────────────── */}
      {editing && payload && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
          <div
            className={`w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-xl border ${
              isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'
            }`}
          >
            <div
              className={`flex items-center justify-between gap-3 px-5 py-3.5 border-b ${
                isDarkMode ? 'border-gray-800' : 'border-gray-200'
              }`}
            >
              <div className="min-w-0">
                <h3 className={`font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {editing.name}
                </h3>
                <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                  Applies immediately to {editing.user_count ?? 0} account
                  {(editing.user_count ?? 0) === 1 ? '' : 's'}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setEditing(null)}
                className={
                  isDarkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-black'
                }
              >
                <X size={18} />
              </button>
            </div>

            <CardBody>
              {/* Super Admin must keep user management or the portal can lock
                  every administrator out of this very screen. The backend
                  refuses it; saying so here avoids a save that just errors. */}
              {editing.name.toLowerCase() === 'super admin' && (
                <div className="mb-3 p-2.5 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-600 dark:text-amber-400 flex items-start gap-2 text-xs">
                  <AlertTriangle size={14} className="mt-0.5 flex-shrink-0" />
                  Super Admin must keep user-management access — it is the only way back into this
                  screen if another role is misconfigured.
                </div>
              )}

              {GROUPS.map((group) => {
                const options = payload.catalogue[group.key];
                const allOn = options.every((option) => selected.includes(option.id));

                return (
                  <div key={group.key} className="mb-5">
                    <div className="flex items-start justify-between gap-3 mb-2">
                      <div className="min-w-0">
                        <p
                          className={`text-xs font-semibold uppercase tracking-wide ${
                            isDarkMode ? 'text-gray-300' : 'text-gray-700'
                          }`}
                        >
                          {group.label}
                        </p>
                        <p
                          className={`text-[11px] mt-0.5 ${
                            isDarkMode ? 'text-gray-500' : 'text-gray-500'
                          }`}
                        >
                          {group.hint}
                        </p>
                      </div>

                      <button
                        type="button"
                        onClick={() =>
                          setSelected((current) => {
                            const ids = options.map((option) => option.id);

                            return allOn
                              ? current.filter((id) => !ids.includes(id))
                              : Array.from(new Set([...current, ...ids]));
                          })
                        }
                        className={`flex-shrink-0 text-xs underline ${
                          isDarkMode ? 'text-blue-400' : 'text-blue-600'
                        }`}
                      >
                        {allOn ? 'Clear all' : 'Select all'}
                      </button>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                      {options.map((option) => (
                        <label
                          key={option.id}
                          className={`flex items-center gap-2 text-sm rounded-lg px-2 py-1 ${
                            isDarkMode ? 'hover:bg-gray-800/60' : 'hover:bg-gray-50'
                          }`}
                        >
                          <input
                            type="checkbox"
                            checked={selected.includes(option.id)}
                            onChange={(event) =>
                              setSelected((current) =>
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
                );
              })}
            </CardBody>

            <div
              className={`flex items-center justify-between gap-2 px-5 py-3 border-t ${
                isDarkMode ? 'border-gray-800' : 'border-gray-200'
              }`}
            >
              <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                {selected.length} permission{selected.length === 1 ? '' : 's'} selected
              </span>
              <span className="flex gap-2">
                <Button variant="outline" onClick={() => setEditing(null)}>
                  Cancel
                </Button>
                <Button
                  variant="primary"
                  onClick={save}
                  disabled={saving}
                  icon={saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
                >
                  Save
                </Button>
              </span>
            </div>
          </div>
        </div>
      )}
    </ReportingPage>
  );
};

export default Roles;
