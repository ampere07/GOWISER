import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  CalendarClock,
  Gauge,
  Network,
  Radio,
  Search,
  Users,
  Zap,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader } from '../components/reporting/Card';
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
import { useTheme } from '../hooks/useTheme';
import { usePermissions } from '../hooks/usePermissions';
import { mikrotikService } from '../services/mikrotikService';
import {
  MikrotikGroup,
  MikrotikOverview,
  MikrotikUser,
} from '../types/mikrotik';
import { formatNumber } from '../utils/format';

interface MikrotikRadiusProps {
  refreshToken: number;
}

/**
 * The Mikrotik Radius Shortcut.
 *
 * A management surface for the User Manager — groups, profiles, rate limits and
 * framed pools — plus the two session controls the whole feature exists for.
 *
 * ── Why the kick buttons are given so much room ───────────────────────
 *
 * Changing a group's rate limit does nothing to anyone already online. RADIUS
 * hands its attributes out at authentication, so a subscriber keeps the speed
 * they connected with until their session ends — which for a fibre customer can
 * be weeks. An operator who changes a limit and walks away has, in practice,
 * changed nothing.
 *
 * That is the trap this page is built around. After every successful change the
 * screen states how many sessions are still running on the old settings and
 * offers the two ways to move them: now, or at the next maintenance window. The
 * count is the important half — "apply to 340 live sessions" is a decision, and
 * an unlabelled "Kick" button is a gamble.
 *
 * Neither control is available without its own permission (see Permissions:
 * ACTION_MIKROTIK_WRITE and ACTION_MIKROTIK_KICK), and both are confirmed before
 * they fire.
 */
const MikrotikRadius: React.FC<MikrotikRadiusProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();
  const { can } = usePermissions();

  const canWrite = can('action.mikrotik.write');
  const canKick = can('action.mikrotik.kick');

  const [data, setData] = useState<MikrotikOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [users, setUsers] = useState<MikrotikUser[]>([]);
  const [userTotal, setUserTotal] = useState(0);
  const [userSearch, setUserSearch] = useState('');
  const [usersLoading, setUsersLoading] = useState(false);

  const [editing, setEditing] = useState<string | null>(null);
  const [draft, setDraft] = useState<{ rate_limit: string; framed_pool: string }>({
    rate_limit: '',
    framed_pool: '',
  });
  const [saving, setSaving] = useState(false);

  const load = useCallback(() => {
    let cancelled = false;

    setLoading(true);

    mikrotikService
      .overview()
      .then((result) => {
        if (cancelled) return;

        setData(result);
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;

        setError(err?.response?.data?.message ?? 'Unable to reach the RADIUS servers.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => load(), [load, refreshToken]);

  const loadUsers = useCallback((search: string) => {
    setUsersLoading(true);

    mikrotikService
      .users(search)
      .then((result) => {
        setUsers(result.rows);
        setUserTotal(result.total);
      })
      .catch((err) => setError(err?.response?.data?.message ?? 'Unable to read the user list.'))
      .finally(() => setUsersLoading(false));
  }, []);

  useEffect(() => {
    // Debounced: the list runs to thousands and each keystroke would otherwise
    // be a request to a router that is also serving live authentication.
    const timer = setTimeout(() => loadUsers(userSearch), 350);

    return () => clearTimeout(timer);
  }, [userSearch, loadUsers, refreshToken]);

  /** Live sessions per group, so a rate-limit change can state its blast radius. */
  const sessionsByGroup = useMemo(() => {
    const counts = new Map<string, number>();

    (data?.sessions.rows ?? []).forEach((session) => {
      counts.set(session.group, (counts.get(session.group) ?? 0) + 1);
    });

    return counts;
  }, [data]);

  const beginEdit = (group: MikrotikGroup) => {
    setEditing(group.name);
    setDraft({ rate_limit: group.rate_limit, framed_pool: group.framed_pool });
    setNotice(null);
  };

  const save = async (group: MikrotikGroup) => {
    setSaving(true);
    setError(null);

    try {
      const result = await mikrotikService.updateGroup(group.name, {
        rate_limit: draft.rate_limit || undefined,
        framed_pool: draft.framed_pool || undefined,
      });

      // The router's own words for what happened, not the form's. See
      // mikrotikService.updateGroup.
      setNotice(result.note);
      setEditing(null);
      load();
    } catch (err: any) {
      setError(
        err?.response?.data?.message ??
          err?.response?.data?.errors?.rate_limit?.[0] ??
          'The change could not be applied.'
      );
    } finally {
      setSaving(false);
    }
  };

  const kick = async (group: string, when: 'now' | 'later') => {
    const live = sessionsByGroup.get(group) ?? 0;

    const confirmed = window.confirm(
      when === 'now'
        ? `Disconnect ${formatNumber(live)} live session(s) in "${group}" right now?\n\n` +
            'Those subscribers will drop immediately and reconnect on the new settings.'
        : `Queue a disconnection for "${group}" at the next maintenance window` +
            `${data?.maintenance_window ? ` (${data.maintenance_window.next})` : ''}?\n\n` +
            'Nobody is disconnected now. The queued kick can be cancelled until it runs.'
    );

    if (!confirmed) return;

    setError(null);

    try {
      const result =
        when === 'now'
          ? await mikrotikService.kickNow({ group, reason: 'Applied new group settings' })
          : await mikrotikService.kickLater({ group, reason: 'Applied new group settings' });

      setNotice(result.message);
      load();
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'The request failed.');
    }
  };

  const cancelKick = async (id: number) => {
    try {
      await mikrotikService.cancelKick(id);
      setNotice('Queued kick cancelled.');
      load();
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'Could not cancel that kick.');
    }
  };

  if (data && !data.configured) {
    return (
      <ReportingPage>
        <PageHeader title="Mikrotik Radius Shortcut" subtitle="User Manager" />
        <Card>
          <div className="flex items-start gap-3 py-6 px-2">
            <Radio size={20} className={isDarkMode ? 'text-gray-600' : 'text-gray-400'} />
            <div>
              <p className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                No RADIUS server is configured
              </p>
              <p className={`text-sm mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                {data.message}
              </p>
            </div>
          </div>
        </Card>
      </ReportingPage>
    );
  }

  const groupsUnreachable = data !== null && !data.groups.reachable;

  return (
    <ReportingPage>
      <PageHeader
        title="Mikrotik Radius Shortcut"
        subtitle={
          <>
            User Manager · groups, profiles and live sessions
            {data?.servers?.length ? ` · ${data.servers.map((s) => s.label).join(', ')}` : ''}
          </>
        }
        actions={
          data?.maintenance_window && (
            <span
              className={`inline-flex items-center gap-1.5 text-xs ${
                isDarkMode ? 'text-gray-400' : 'text-gray-500'
              }`}
            >
              <CalendarClock size={13} />
              Maintenance window {data.maintenance_window.start}–{data.maintenance_window.end}
              {data.maintenance_window.open_now && (
                <Pill tone="success">open now</Pill>
              )}
            </span>
          )
        }
      />

      {error && <ErrorBanner message={error} />}

      {notice && (
        <div
          className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
            isDarkMode
              ? 'border-blue-800/60 bg-blue-500/10 text-blue-200'
              : 'border-blue-200 bg-blue-50 text-blue-800'
          }`}
        >
          <Zap size={15} className="mt-0.5 flex-shrink-0" />
          <span className="min-w-0">{notice}</span>
        </div>
      )}

      {/* An unreachable router is stated, never rendered as an empty list. */}
      {groupsUnreachable && (
        <div
          className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
            isDarkMode
              ? 'border-amber-800/60 bg-amber-500/10 text-amber-200'
              : 'border-amber-200 bg-amber-50 text-amber-800'
          }`}
        >
          <AlertTriangle size={15} className="mt-0.5 flex-shrink-0" />
          <div className="min-w-0">
            <p>
              <strong>No RADIUS server answered.</strong> The lists below are empty because nothing
              could be read — not because nothing is configured.
            </p>
            {Object.entries(data?.groups.errors ?? {}).map(([server, message]) => (
              <p key={server} className="text-xs mt-0.5 opacity-80">
                {server}: {message}
              </p>
            ))}
          </div>
        </div>
      )}

      {/* ── USER GROUPS: rate limits and pools ───────────────────────── */}
      <Card flush>
        <CardHeader
          title="User Groups"
          subtitle="Rate limit and framed pool per group"
          icon={<Gauge size={16} />}
          badge={`${formatNumber(data?.groups.rows.length ?? 0)} groups`}
        />
        <Table>
          <Thead>
            <Th>Group</Th>
            <Th>Rate Limit</Th>
            <Th>Framed Pool</Th>
            <Th align="right">Live Sessions</Th>
            <Th align="right">Actions</Th>
          </Thead>
          <tbody>
            <TableState
              colSpan={5}
              loading={loading && !data}
              error={null}
              empty={(data?.groups.rows.length ?? 0) === 0}
              emptyMessage={
                groupsUnreachable
                  ? 'Could not read the group list.'
                  : 'No user groups are defined on this server.'
              }
            />

            {data?.groups.rows.map((group) => {
              const live = sessionsByGroup.get(group.name) ?? 0;
              const isEditing = editing === group.name;

              return (
                <Tr key={group.id || group.name}>
                  <Td className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                    {group.name}
                    {group.comment && (
                      <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        {group.comment}
                      </p>
                    )}
                  </Td>

                  <Td>
                    {isEditing ? (
                      <input
                        value={draft.rate_limit}
                        onChange={(event) =>
                          setDraft((current) => ({ ...current, rate_limit: event.target.value }))
                        }
                        placeholder="150M/150M"
                        className={`${controlClass} !py-1 !text-xs w-32`}
                        aria-label={`Rate limit for ${group.name}`}
                      />
                    ) : (
                      <span className="tabular-nums">{group.rate_limit || '—'}</span>
                    )}
                  </Td>

                  <Td>
                    {isEditing ? (
                      <input
                        value={draft.framed_pool}
                        onChange={(event) =>
                          setDraft((current) => ({ ...current, framed_pool: event.target.value }))
                        }
                        placeholder="pool-nat444"
                        className={`${controlClass} !py-1 !text-xs w-36`}
                        aria-label={`Framed pool for ${group.name}`}
                      />
                    ) : (
                      group.framed_pool || '—'
                    )}
                  </Td>

                  <Td align="right" className="tabular-nums">
                    {live > 0 ? <Pill tone="info">{formatNumber(live)}</Pill> : '—'}
                  </Td>

                  <Td align="right">
                    <div className="flex items-center gap-1.5 justify-end flex-wrap">
                      {isEditing ? (
                        <>
                          <Button
                            variant="primary"
                            onClick={() => save(group)}
                            disabled={saving}
                          >
                            {saving ? 'Saving…' : 'Save'}
                          </Button>
                          <Button onClick={() => setEditing(null)} disabled={saving}>
                            Cancel
                          </Button>
                        </>
                      ) : (
                        <>
                          {canWrite && <Button onClick={() => beginEdit(group)}>Edit</Button>}

                          {/* Only offered where there is something to kick. A
                              disabled button on an idle group would be one more
                              thing to reason about on a screen whose buttons
                              disconnect people. */}
                          {canKick && live > 0 && (
                            <>
                              <Button variant="danger" onClick={() => kick(group.name, 'now')}>
                                Kick Now
                              </Button>
                              <Button onClick={() => kick(group.name, 'later')}>Kick Later</Button>
                            </>
                          )}
                        </>
                      )}
                    </div>
                  </Td>
                </Tr>
              );
            })}
          </tbody>
        </Table>
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* ── PROFILES ─────────────────────────────────────────────── */}
        <Card flush>
          <CardHeader title="Profiles" subtitle="Billing plans in User Manager" icon={<Network size={16} />} />
          <Table>
            <Thead>
              <Th>Profile</Th>
              <Th align="right">Price</Th>
              <Th align="right">Validity</Th>
            </Thead>
            <tbody>
              <TableState
                colSpan={3}
                loading={loading && !data}
                error={null}
                empty={(data?.profiles.rows.length ?? 0) === 0}
                emptyMessage="No profiles defined."
              />
              {data?.profiles.rows.map((profile) => (
                <Tr key={profile.id || profile.name}>
                  <Td className={isDarkMode ? 'text-gray-100' : 'text-gray-900'}>{profile.name}</Td>
                  <Td align="right" className="tabular-nums">
                    {profile.price || '—'}
                  </Td>
                  <Td align="right">{profile.validity || '—'}</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </Card>

        {/* ── LIMITATIONS ──────────────────────────────────────────── */}
        <Card flush>
          <CardHeader
            title="Rate Limits"
            subtitle="Named limitation profiles"
            icon={<Gauge size={16} />}
          />
          <Table>
            <Thead>
              <Th>Limitation</Th>
              <Th>Rate Limit</Th>
              <Th align="right">Uptime Cap</Th>
            </Thead>
            <tbody>
              <TableState
                colSpan={3}
                loading={loading && !data}
                error={null}
                empty={(data?.limitations.rows.length ?? 0) === 0}
                emptyMessage="No limitations defined."
              />
              {data?.limitations.rows.map((limit) => (
                <Tr key={limit.id || limit.name}>
                  <Td className={isDarkMode ? 'text-gray-100' : 'text-gray-900'}>{limit.name}</Td>
                  <Td className="tabular-nums">{limit.rate_limit || '—'}</Td>
                  <Td align="right">{limit.uptime_limit || '—'}</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </Card>
      </div>

      {/* ── QUEUED KICKS ─────────────────────────────────────────────── */}
      {(data?.queued.length ?? 0) > 0 && (
        <Card flush>
          <CardHeader
            title="Queued Session Kicks"
            subtitle={
              data?.maintenance_window
                ? `Next window opens ${data.maintenance_window.next}`
                : 'Waiting for the maintenance window'
            }
            icon={<CalendarClock size={16} />}
          />
          <Table>
            <Thead>
              <Th>Target</Th>
              <Th>Requested By</Th>
              <Th>Scheduled</Th>
              <Th>Status</Th>
              <Th align="right">Result</Th>
              <Th align="right" />
            </Thead>
            <tbody>
              {data?.queued.map((kick) => (
                <Tr key={kick.id}>
                  <Td className={isDarkMode ? 'text-gray-100' : 'text-gray-900'}>
                    {kick.target}
                    {kick.reason && (
                      <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        {kick.reason}
                      </p>
                    )}
                  </Td>
                  <Td>{kick.requested_by ?? '—'}</Td>
                  <Td className="whitespace-nowrap">{kick.scheduled_for ?? '—'}</Td>
                  <Td>
                    <Pill
                      tone={
                        kick.status === 'done'
                          ? 'success'
                          : kick.status === 'failed'
                          ? 'danger'
                          : kick.status === 'cancelled'
                          ? 'neutral'
                          : 'warning'
                      }
                    >
                      {kick.status}
                    </Pill>
                  </Td>
                  <Td align="right" className="tabular-nums">
                    {kick.executed_at
                      ? `${formatNumber(kick.sessions_killed)} killed${
                          kick.sessions_failed > 0 ? `, ${kick.sessions_failed} failed` : ''
                        }`
                      : '—'}
                  </Td>
                  <Td align="right">
                    {canKick && kick.status === 'pending' && (
                      <Button onClick={() => cancelKick(kick.id)}>Cancel</Button>
                    )}
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {/* ── USERS ────────────────────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="Users"
          subtitle={`${formatNumber(userTotal)} matching`}
          icon={<Users size={16} />}
          actions={
            <span className="relative">
              <Search
                size={13}
                className={`absolute left-2 top-1/2 -translate-y-1/2 ${
                  isDarkMode ? 'text-gray-500' : 'text-gray-400'
                }`}
              />
              <input
                type="search"
                value={userSearch}
                onChange={(event) => setUserSearch(event.target.value)}
                placeholder="Search username or group…"
                className={`${controlClass} !pl-7 !py-1 !text-xs w-52`}
                aria-label="Search RADIUS users"
              />
            </span>
          }
        />
        <div className="max-h-[420px] overflow-y-auto">
          <Table>
            <Thead>
              <Th>Username</Th>
              <Th>Group</Th>
              <Th align="right">Shared</Th>
              <Th align="right">State</Th>
            </Thead>
            <tbody>
              <TableState
                colSpan={4}
                loading={usersLoading && users.length === 0}
                error={null}
                empty={users.length === 0}
                emptyMessage={
                  userSearch.trim() ? 'No user matches that search.' : 'No users on this server.'
                }
              />
              {users.map((user) => (
                <Tr key={user.id || user.username}>
                  <Td className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                    {user.username}
                  </Td>
                  <Td>{user.group || '—'}</Td>
                  <Td align="right" className="tabular-nums">
                    {user.shared_users || '—'}
                  </Td>
                  <Td align="right">
                    <Pill tone={user.disabled ? 'danger' : 'success'}>
                      {user.disabled ? 'disabled' : 'enabled'}
                    </Pill>
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </div>

        {userTotal > users.length && (
          <p
            className={`px-4 sm:px-5 py-2 text-xs ${
              isDarkMode ? 'text-gray-500' : 'text-gray-400'
            }`}
          >
            Showing the first {formatNumber(users.length)} of {formatNumber(userTotal)}. Narrow the
            search to see the rest.
          </p>
        )}
      </Card>
    </ReportingPage>
  );
};

export default MikrotikRadius;
