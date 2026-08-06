import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertTriangle,
  CalendarClock,
  Check,
  Gauge,
  Loader2,
  Lock,
  Radio,
  RefreshCw,
  Search,
  Server,
  X,
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
import { useAutoRefresh } from '../hooks/useAutoRefresh';
import { mikrotikService } from '../services/mikrotikService';
import {
  MikrotikGroup,
  MikrotikOverview,
  MikrotikUser,
  RateLimitPreview,
} from '../types/mikrotik';
import { ACTION } from '../types/rbac';
import { formatNumber } from '../utils/format';

interface MikrotikRadiusProps {
  refreshToken: number;
}

type TabKey = 'groups' | 'sessions' | 'scheduled';

/**
 * The tabs this module keeps.
 *
 * Winbox's User Manager also lists Users, Profiles, Limitations and Attributes.
 * They are gone deliberately: three of them are read-only lists nobody
 * administers from a monitoring portal, and the module exists for one job —
 * changing what a group is allowed and moving the people affected onto it. Two
 * tabs is the whole of that job.
 *
 * The per-subscriber group switcher that lived on the Users tab moved onto
 * Sessions rather than being deleted with it. It is the same action against the
 * same accounts, and Sessions is arguably where it belonged all along: you can
 * see who is online, on which group, before you move them.
 */
const TABS: { key: TabKey; label: string; icon: React.ElementType }[] = [
  { key: 'groups', label: 'User Groups', icon: Gauge },
  { key: 'sessions', label: 'Sessions', icon: Zap },
  { key: 'scheduled', label: 'Scheduled', icon: CalendarClock },
];

/** The timezone every scheduled re-authorisation is named in. Matches the API. */
const TIMEZONE = 'Asia/Manila';

/**
 * A Date rendered as a datetime-local value in Manila, not in the browser's zone.
 *
 * The input is labelled GMT+8 and the server reads it as GMT+8, so it has to be
 * *seeded* as GMT+8 too. Seeding it from the browser's clock would put an
 * operator in Singapore an hour out, and they would have no way to tell — the
 * field would simply be wrong and look right.
 */
const manilaInputValue = (date: Date): string => {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(date);

  const get = (type: string) => parts.find((part) => part.type === type)?.value ?? '';

  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
};

/**
 * MikroTik RADIUS — User Manager control.
 *
 * Laid out as Winbox lays out User Manager, because the people who use this
 * already know that screen: tabbed collections along the top — Users, User
 * Groups, Sessions, Profiles, Limitations — each a plain table of the same rows
 * the router shows. What this adds is the part Winbox makes you reason about
 * yourself.
 *
 * ── The trap the whole page is built around ───────────────────────────
 *
 * Changing a group's rate limit does nothing to anyone already online. RADIUS
 * hands its attributes out at authentication, so a subscriber keeps the speed
 * they connected with until their session ends — which for a fibre customer can
 * be weeks. An operator who changes a limit and walks away has, in practice,
 * changed nothing.
 *
 * So after every successful change the screen states how many sessions are still
 * running on the old settings and offers the three ways to move them: now, at a
 * time named in GMT+8, or at the next maintenance window. The count is the
 * important half — "apply to 340 live sessions" is a decision, and an unlabelled
 * Kick button is a gamble.
 *
 * Neither control is available without its own permission (action.mikrotik.write
 * and action.mikrotik.kick), the module is restricted to executive roles at the
 * route, and every write is audited before it is attempted.
 */
const MikrotikRadius: React.FC<MikrotikRadiusProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { can, user } = usePermissions();

  const canWrite = can(ACTION.mikrotikWrite);
  const canKick = can(ACTION.mikrotikKick);

  const [tab, setTab] = useState<TabKey>('groups');

  const [data, setData] = useState<MikrotikOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [reloads, setReloads] = useState(0);

  const [sessionSearch, setSessionSearch] = useState('');

  /** The group being edited, and the user being moved. Only one is ever open. */
  const [editingGroup, setEditingGroup] = useState<MikrotikGroup | null>(null);
  const [movingUser, setMovingUser] = useState<MikrotikUser | null>(null);
  /** The re-authorisation prompt: what to disconnect, and how many that is. */
  const [reauth, setReauth] = useState<{ label: string; group?: string; usernames?: string[]; live: number } | null>(
    null
  );

  const reload = useCallback(() => setReloads((n) => n + 1), []);

  useEffect(() => {
    let cancelled = false;

    setLoading(true);

    mikrotikService
      .overview()
      .then((result) => {
        if (cancelled) return;

        setData(result);
        setError(null);
        setForbidden(false);
      })
      .catch((err) => {
        if (cancelled) return;

        if (err?.response?.status === 403) {
          setForbidden(true);
          setError(null);
          return;
        }

        setError(err?.response?.data?.message ?? 'Unable to reach the RADIUS servers.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [refreshToken, reloads]);

  // Paused while a dialog is open: a table reloading underneath somebody who is
  // half way through choosing what to disconnect is worse than a stale one.
  const { seconds, lastRun } = useAutoRefresh(
    'mikrotik_refresh',
    reload,
    editingGroup !== null || movingUser !== null || reauth !== null
  );

  const sessionsByGroup = data?.sessions_by_group ?? {};

  const groupNames = useMemo(
    () => (data?.groups.rows ?? []).map((group) => group.name).filter(Boolean),
    [data]
  );

  /**
   * Live sessions matching the search box.
   *
   * Filtered in the browser, unlike the user list this replaced. The session
   * list is bounded by how many people are online *right now* and has already
   * been fetched in full for the group counts, so a round trip per keystroke
   * would be a request to a router that is also serving live authentication —
   * to filter a list already in memory.
   */
  const visibleSessions = useMemo(() => {
    const rows = data?.sessions.rows ?? [];
    const needle = sessionSearch.trim().toLowerCase();

    if (needle === '') return rows;

    return rows.filter((session) =>
      [session.user, session.group, session.caller_id, session.address, session.nas]
        .join(' ')
        .toLowerCase()
        .includes(needle)
    );
  }, [data, sessionSearch]);

  const succeeded = (message: string) => {
    setNotice(message);
    setError(null);
    reload();
  };

  const failed = (err: any, fallback: string) => {
    setError(
      err?.response?.data?.errors?.rate_limit?.[0] ??
        err?.response?.data?.errors?.group?.[0] ??
        err?.response?.data?.message ??
        fallback
    );
  };

  if (forbidden) {
    return (
      <ReportingPage>
        <PageHeader title="MikroTik RADIUS" subtitle="User Manager control" />
        <Card>
          <div className="flex items-start gap-3 py-6 px-2">
            <Lock size={20} className={isDarkMode ? 'text-gray-600' : 'text-gray-400'} />
            <div>
              <p className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                Restricted to executive roles
              </p>
              <p className={`text-sm mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                This module changes live network configuration and can disconnect subscribers, so it
                is pinned to the executive roles rather than granted by permission.
                {user?.role ? <> Your role ({user.role}) does not have access.</> : null}
              </p>
            </div>
          </div>
        </Card>
      </ReportingPage>
    );
  }

  if (data && !data.configured) {
    return (
      <ReportingPage>
        <PageHeader title="MikroTik RADIUS" subtitle="User Manager control" />
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

  const unreachable = data !== null && !data.groups.reachable;

  return (
    <ReportingPage>
      <PageHeader
        title="MikroTik RADIUS"
        subtitle={
          <>
            User Manager
            {data?.servers?.length ? ` · ${data.servers.map((s) => s.label).join(', ')}` : ''}
            {data?.source === 'environment' && ' · configured from the environment'}
            {seconds > 0 && (
              <>
                {' '}
                · auto-refresh {seconds < 60 ? `${seconds}s` : `${seconds / 60}m`}
                {lastRun && ` · updated ${lastRun.toLocaleTimeString()}`}
              </>
            )}
          </>
        }
        actions={
          <div className="flex items-center gap-2">
            {data?.timezone && (
              <span
                className={`hidden sm:inline-flex items-center gap-1.5 text-xs tabular-nums ${
                  isDarkMode ? 'text-gray-400' : 'text-gray-500'
                }`}
                title={`Server time in ${data.timezone.name}`}
              >
                <CalendarClock size={13} />
                {data.timezone.now} {data.timezone.label}
              </span>
            )}
            <Button
              icon={<RefreshCw size={14} className={loading ? 'animate-spin' : ''} />}
              onClick={reload}
              disabled={loading}
            >
              Refresh
            </Button>
          </div>
        }
      />

      {error && <ErrorBanner message={error} />}

      {notice && (
        <div
          className={`flex items-start justify-between gap-2 rounded-xl border px-3 py-2 text-sm ${
            isDarkMode
              ? 'border-blue-800/60 bg-blue-500/10 text-blue-200'
              : 'border-blue-200 bg-blue-50 text-blue-800'
          }`}
        >
          <span className="flex items-start gap-2 min-w-0">
            <Zap size={15} className="mt-0.5 flex-shrink-0" />
            <span className="min-w-0">{notice}</span>
          </span>
          <button type="button" onClick={() => setNotice(null)} className="flex-shrink-0 opacity-70">
            <X size={14} />
          </button>
        </div>
      )}

      {/* An unreachable router is stated, never rendered as an empty list. */}
      {unreachable && (
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
              <strong>No RADIUS server answered.</strong> The tables below are empty because nothing
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

      {/* ── Tabs ──────────────────────────────────────────────────────── */}
      {/* Winbox's own layout: the collections along the top, one table below.
          Kept because the operators using this already know that screen — a
          rearrangement would be a new thing to learn for no gain. */}
      <div
        className={`flex items-center gap-1 overflow-x-auto rounded-xl border p-1 ${
          isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'
        }`}
        role="tablist"
      >
        {TABS.map(({ key, label, icon: Icon }) => {
          const active = tab === key;
          const badge = countFor(key, data);

          return (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => setTab(key)}
              className={`flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
                active
                  ? isDarkMode
                    ? 'bg-blue-500/15 text-blue-300'
                    : 'bg-blue-50 text-blue-700'
                  : isDarkMode
                  ? 'text-gray-400 hover:bg-gray-800 hover:text-gray-200'
                  : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
              }`}
            >
              <Icon size={14} />
              {label}
              {badge !== null && (
                <span className={`tabular-nums text-xs ${active ? '' : 'opacity-60'}`}>
                  {formatNumber(badge)}
                </span>
              )}
            </button>
          );
        })}
      </div>

      {/* ── USER GROUPS ───────────────────────────────────────────────── */}
      {tab === 'groups' && (
        <Card flush>
          <CardHeader
            title="User Groups"
            subtitle="Mikrotik-Rate-Limit and Framed-Pool per group"
            icon={<Gauge size={16} />}
            badge={`${formatNumber(data?.groups.rows.length ?? 0)} groups`}
          />
          <Table>
            <Thead>
              <Th>Name</Th>
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
                  unreachable
                    ? 'Could not read the group list.'
                    : 'No user groups are defined on this server.'
                }
              />

              {data?.groups.rows.map((group) => {
                const live = sessionsByGroup[group.name] ?? 0;

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
                    <Td className="tabular-nums font-mono text-xs">{group.rate_limit || '—'}</Td>
                    <Td className="font-mono text-xs">{group.framed_pool || '—'}</Td>
                    <Td align="right" className="tabular-nums">
                      {live > 0 ? <Pill tone="info">{formatNumber(live)}</Pill> : '—'}
                    </Td>
                    <Td align="right">
                      <div className="flex items-center gap-1.5 justify-end flex-wrap">
                        {canWrite && (
                          <Button onClick={() => setEditingGroup(group)}>Edit</Button>
                        )}

                        {/* Only offered where there is something to disconnect.
                            A disabled button on an idle group would be one more
                            thing to reason about on a screen whose buttons cut
                            people off. */}
                        {canKick && live > 0 && (
                          <Button
                            variant="danger"
                            onClick={() =>
                              setReauth({ label: group.name, group: group.name, live })
                            }
                          >
                            Re-authorise
                          </Button>
                        )}
                      </div>
                    </Td>
                  </Tr>
                );
              })}
            </tbody>
          </Table>
        </Card>
      )}

      {/* ── SESSIONS ──────────────────────────────────────────────────── */}
      {tab === 'sessions' && (
        <Card flush>
          <CardHeader
            title="Sessions"
            subtitle="Everyone authenticated right now"
            icon={<Zap size={16} />}
            badge={`${formatNumber(visibleSessions.length)} of ${formatNumber(
              data?.sessions.rows.length ?? 0
            )} live`}
            actions={<SearchBox value={sessionSearch} onChange={setSessionSearch} />}
          />
          <div className="max-h-[560px] overflow-y-auto">
            <Table>
              <Thead>
                <Th>User</Th>
                <Th>Group</Th>
                <Th>Address</Th>
                <Th>Caller ID</Th>
                <Th>NAS</Th>
                <Th align="right">Uptime</Th>
                {(canWrite || canKick) && <Th align="right">Actions</Th>}
              </Thead>
              <tbody>
                <TableState
                  colSpan={canWrite || canKick ? 7 : 6}
                  loading={loading && !data}
                  error={null}
                  empty={visibleSessions.length === 0}
                  emptyMessage={
                    data && !data.sessions.reachable
                      ? 'Could not read the session list.'
                      : sessionSearch.trim()
                      ? 'No live session matches that search.'
                      : 'Nobody is connected.'
                  }
                />
                {visibleSessions.map((session) => (
                  <Tr key={session.id || `${session.user}-${session.address}`}>
                    <Td className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                      {session.user}
                    </Td>
                    <Td>{session.group || '—'}</Td>
                    <Td className="font-mono text-xs">{session.address || '—'}</Td>
                    <Td className="font-mono text-xs">{session.caller_id || '—'}</Td>
                    <Td className="font-mono text-xs">{session.nas || '—'}</Td>
                    <Td align="right" className="tabular-nums whitespace-nowrap">
                      {session.uptime || '—'}
                    </Td>

                    {/* The group switcher that used to live on the Users tab.
                        Same action, same accounts — and here you can see who is
                        online, on which group, before moving them. */}
                    {(canWrite || canKick) && (
                      <Td align="right">
                        <div className="flex items-center gap-1.5 justify-end flex-wrap">
                          {canWrite && (
                            <Button
                              onClick={() =>
                                setMovingUser({
                                  id: '',
                                  username: session.user,
                                  group: session.group,
                                  shared_users: '',
                                  caller_id: session.caller_id,
                                  attributes: '',
                                  disabled: false,
                                  comment: '',
                                  sessions: 1,
                                  online: true,
                                  address: session.address,
                                  uptime: session.uptime,
                                })
                              }
                              title="Move this subscriber to another group"
                            >
                              Group
                            </Button>
                          )}
                          {canKick && (
                            <Button
                              variant="danger"
                              onClick={() =>
                                setReauth({
                                  label: session.user,
                                  usernames: [session.user],
                                  live: 1,
                                })
                              }
                            >
                              Re-auth
                            </Button>
                          )}
                        </div>
                      </Td>
                    )}
                  </Tr>
                ))}
              </tbody>
            </Table>
          </div>
        </Card>
      )}

      {/* ── SCHEDULED ─────────────────────────────────────────────────── */}
      {tab === 'scheduled' && (
        <Card flush>
          <CardHeader
            title="Scheduled Re-authorisations"
            subtitle={
              data?.maintenance_window
                ? `Maintenance window ${data.maintenance_window.start}–${data.maintenance_window.end} · next opens ${data.maintenance_window.next}`
                : 'Queued session disconnections'
            }
            icon={<CalendarClock size={16} />}
            actions={
              data?.maintenance_window?.open_now && <Pill tone="success">window open now</Pill>
            }
          />
          <Table>
            <Thead>
              <Th>Target</Th>
              <Th>When</Th>
              <Th>Requested By</Th>
              <Th>Status</Th>
              <Th align="right">Result</Th>
              <Th align="right" />
            </Thead>
            <tbody>
              <TableState
                colSpan={6}
                loading={loading && !data}
                error={null}
                empty={(data?.queued.length ?? 0) === 0}
                emptyMessage="Nothing is queued."
              />
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
                  <Td className="whitespace-nowrap tabular-nums">
                    {kick.scheduled_for ?? '—'}
                    <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                      {kick.mode === 'at' ? 'GMT+8' : 'maintenance window'}
                    </p>
                  </Td>
                  <Td>{kick.requested_by ?? '—'}</Td>
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
                    {kick.result_note && (
                      <p className="text-xs text-red-500 mt-0.5 max-w-xs truncate" title={kick.result_note}>
                        {kick.result_note}
                      </p>
                    )}
                  </Td>
                  <Td align="right">
                    {canKick && kick.status === 'pending' && (
                      <Button
                        onClick={() =>
                          mikrotikService
                            .cancelKick(kick.id)
                            .then(() => succeeded('Scheduled re-authorisation cancelled.'))
                            .catch((err) => failed(err, 'Could not cancel that.'))
                        }
                      >
                        Cancel
                      </Button>
                    )}
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {/* ── Dialogs ───────────────────────────────────────────────────── */}
      {editingGroup && (
        <GroupEditor
          group={editingGroup}
          liveSessions={sessionsByGroup[editingGroup.name] ?? 0}
          canKick={canKick}
          onClose={() => setEditingGroup(null)}
          onSaved={(result) => {
            setEditingGroup(null);
            succeeded(result.note);

            // Straight into the re-authorisation prompt when the change stranded
            // anyone. The whole point of this module is that a rate limit change
            // with live sessions is *half* an action, and closing the dialog here
            // is where an operator would otherwise walk away believing it done.
            if (result.live_sessions > 0 && canKick) {
              setReauth({
                label: result.after.name,
                group: result.after.name,
                live: result.live_sessions,
              });
            }
          }}
          onError={(message) => setError(message)}
        />
      )}

      {movingUser && (
        <GroupSwitcher
          user={movingUser}
          groups={groupNames}
          canKick={canKick}
          onClose={() => setMovingUser(null)}
          onSaved={(result, username) => {
            setMovingUser(null);
            succeeded(result.note);

            if (result.live_sessions > 0 && canKick) {
              setReauth({ label: username, usernames: [username], live: result.live_sessions });
            }
          }}
          onError={(message) => setError(message)}
        />
      )}

      {reauth && (
        <ReauthDialog
          target={reauth}
          maintenanceNext={data?.maintenance_window?.next}
          onClose={() => setReauth(null)}
          onDone={(message) => {
            setReauth(null);
            succeeded(message);
          }}
          onError={(message) => setError(message)}
        />
      )}
    </ReportingPage>
  );
};

/** The row count shown on a tab, or null for tabs where a count means nothing. */
const countFor = (key: TabKey, data: MikrotikOverview | null): number | null => {
  if (!data) return null;

  switch (key) {
    case 'groups':
      return data.groups.rows.length;
    case 'sessions':
      return data.sessions.rows.length;
    case 'scheduled':
      return data.queued.filter((kick) => kick.status === 'pending').length;
    default:
      return null;
  }
};

// ── Dialogs ───────────────────────────────────────────────────────────

const Dialog: React.FC<{
  title: string;
  onClose: () => void;
  footer: React.ReactNode;
  children: React.ReactNode;
}> = ({ title, onClose, footer, children }) => {
  const isDarkMode = useTheme();

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div
        className={`w-full max-w-lg rounded-xl border max-h-[90vh] overflow-y-auto ${
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

        <div className="p-5">{children}</div>

        <div
          className={`flex justify-end gap-2 px-5 py-3 border-t ${
            isDarkMode ? 'border-gray-800' : 'border-gray-200'
          }`}
        >
          {footer}
        </div>
      </div>
    </div>
  );
};

/**
 * Editing one group's rate limit and framed pool.
 *
 * The rate-limit field accepts what people actually type — "250mb", "250 Mbps",
 * "1.5gb/512kb", or RouterOS's own "250M/250M" — and shows what it resolves to
 * underneath as it is typed.
 *
 * ── Why the preview is a round trip ───────────────────────────────────
 *
 * The conversion runs on the server, not here. A second implementation of the
 * kb/mb/gb arithmetic in TypeScript would eventually drift from the one that
 * writes to the router, and the drift would surface as a subscriber on a speed
 * nobody chose. Debounced, so a typed value is one request rather than one per
 * keystroke.
 */
const GroupEditor: React.FC<{
  group: MikrotikGroup;
  liveSessions: number;
  canKick: boolean;
  onClose: () => void;
  onSaved: (result: Awaited<ReturnType<typeof mikrotikService.updateGroup>>) => void;
  onError: (message: string) => void;
}> = ({ group, liveSessions, onClose, onSaved, onError }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [down, setDown] = useState<RateParts>(() => splitRate(group.rate_limit, 0));
  const [up, setUp] = useState<RateParts>(() => splitRate(group.rate_limit, 1));

  /** The two halves recomposed into the string the API parses. */
  const rateLimit = useMemo(() => composeRate(down, up), [down, up]);
  const [framedPool, setFramedPool] = useState(group.framed_pool);
  const [comment, setComment] = useState(group.comment);
  const [saving, setSaving] = useState(false);

  const [preview, setPreview] = useState<RateLimitPreview | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [previewing, setPreviewing] = useState(false);

  // Guards against an out-of-order response overwriting a newer one: the user
  // types faster than the network answers, and the reply to "250m" must not land
  // on top of the reply to "250mb".
  const requestId = useRef(0);

  useEffect(() => {
    const value = rateLimit.trim();

    if (value === '') {
      setPreview(null);
      setPreviewError(null);
      return;
    }

    const id = ++requestId.current;
    const timer = setTimeout(() => {
      setPreviewing(true);

      mikrotikService
        .previewRateLimit(value)
        .then((result) => {
          if (id !== requestId.current) return;

          setPreview(result);
          setPreviewError(null);
        })
        .catch((err) => {
          if (id !== requestId.current) return;

          setPreview(null);
          setPreviewError(err?.response?.data?.message ?? 'That is not a rate limit.');
        })
        .finally(() => {
          if (id === requestId.current) setPreviewing(false);
        });
    }, 300);

    return () => clearTimeout(timer);
  }, [rateLimit]);

  const save = async () => {
    setSaving(true);

    try {
      const result = await mikrotikService.updateGroup(group.name, {
        rate_limit: rateLimit.trim() || undefined,
        framed_pool: framedPool.trim() || undefined,
        comment: comment.trim() || undefined,
      });

      onSaved(result);
    } catch (err: any) {
      onError(
        err?.response?.data?.errors?.rate_limit?.[0] ??
          err?.response?.data?.message ??
          'The change could not be applied.'
      );
    } finally {
      setSaving(false);
    }
  };

  const unchanged =
    rateLimit.trim() === group.rate_limit &&
    framedPool.trim() === group.framed_pool &&
    comment.trim() === group.comment;

  return (
    <Dialog
      title={`User Group · ${group.name}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button
            variant="primary"
            onClick={save}
            // Blocked on an unreadable rate rather than letting the server
            // reject it: the message is already on screen next to the field.
            disabled={saving || unchanged || previewError !== null}
            icon={saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
          >
            Apply
          </Button>
        </>
      }
    >
      {/* ── Rate limit ──────────────────────────────────────────────
          A number and a unit per direction, rather than a text field that
          expects RouterOS syntax. "250M/250M" is a format an operator has to
          have been taught, and every one of its failure modes — a missing
          unit, a lower-case M, a comma for the slash — is silently accepted by
          the router and throttles a region. Two spinners and two dropdowns
          cannot express any of them.

          The composed string still goes to the server to be parsed and
          normalised (see mikrotikService.previewRateLimit): the conversion has
          one implementation, on the side that writes to the router. */}
      <span className={`block text-xs mb-1.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        Mikrotik-Rate-Limit
      </span>

      <div className="grid grid-cols-2 gap-3 mb-1">
        <RateField
          label="Download"
          amount={down.amount}
          unit={down.unit}
          onChange={setDown}
          autoFocus
        />
        <RateField
          label="Upload"
          amount={up.amount}
          unit={up.unit}
          onChange={setUp}
          // Symmetric is the normal case for fibre, so the upload side offers to
          // follow the download rather than making it be typed twice.
          onMatch={() => setUp({ ...down })}
          matched={down.amount === up.amount && down.unit === up.unit}
        />
      </div>

      {/* What the router will actually receive, stated before it is sent. */}
      <div className="min-h-[34px] mb-3">
        {previewing ? (
          <p className={`text-xs flex items-center gap-1.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
            <Loader2 size={12} className="animate-spin" /> reading…
          </p>
        ) : previewError ? (
          <p className="text-xs text-red-500">{previewError}</p>
        ) : preview ? (
          <>
            <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              Sends{' '}
              <span className={`font-mono font-semibold ${isDarkMode ? 'text-blue-300' : 'text-blue-700'}`}>
                {preview.value}
              </span>{' '}
              — {describeBps(preview.rx_bps)} down, {describeBps(preview.tx_bps)} up
            </p>
            {preview.warning && (
              <p className="text-xs text-amber-600 dark:text-amber-400 mt-0.5">{preview.warning}</p>
            )}
          </>
        ) : (
          <p className={`text-xs ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
            Mbps becomes M, Kbps becomes k, Gbps becomes G — converted for you.
          </p>
        )}
      </div>

      <label className="block mb-3">
        <span className={`block text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
          Framed-Pool
        </span>
        <input
          className={`${controlClass} w-full font-mono`}
          value={framedPool}
          onChange={(event) => setFramedPool(event.target.value)}
          placeholder="pool-nat444"
        />
      </label>

      <label className="block mb-3">
        <span className={`block text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
          Comment
        </span>
        <input
          className={`${controlClass} w-full`}
          value={comment}
          onChange={(event) => setComment(event.target.value)}
          placeholder="optional"
        />
      </label>

      {/* The whole reason this module exists, said before the button is pressed
          rather than after. */}
      <div
        className={`flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs ${
          liveSessions > 0
            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
            : isDarkMode
            ? 'bg-gray-800/60 text-gray-400'
            : 'bg-gray-50 text-gray-500'
        }`}
      >
        <AlertTriangle size={14} className="mt-0.5 flex-shrink-0" />
        <span>
          {liveSessions > 0 ? (
            <>
              <strong>{formatNumber(liveSessions)} subscribers are online in this group.</strong>{' '}
              RADIUS hands its attributes out at authentication, so they keep their current speed
              until their session ends — which can be weeks. Applying this change will offer to
              re-authorise them.
            </>
          ) : (
            <>
              Nobody is connected in this group, so the change takes effect on the next
              authentication with nothing to disconnect.
            </>
          )}
        </span>
      </div>
    </Dialog>
  );
};

/** Moving one user into another group. */
const GroupSwitcher: React.FC<{
  user: MikrotikUser;
  groups: string[];
  canKick: boolean;
  onClose: () => void;
  onSaved: (result: Awaited<ReturnType<typeof mikrotikService.moveUser>>, username: string) => void;
  onError: (message: string) => void;
}> = ({ user, groups, onClose, onSaved, onError }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [group, setGroup] = useState(user.group);
  const [saving, setSaving] = useState(false);

  const save = async () => {
    setSaving(true);

    try {
      onSaved(await mikrotikService.moveUser(user.username, group), user.username);
    } catch (err: any) {
      onError(
        err?.response?.data?.errors?.group?.[0] ??
          err?.response?.data?.message ??
          'The user could not be moved.'
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog
      title={`Group · ${user.username}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button
            variant="primary"
            onClick={save}
            disabled={saving || group === user.group || !group}
            icon={saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
          >
            Move
          </Button>
        </>
      }
    >
      <dl className={`text-xs space-y-1 mb-4 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        <div className="flex justify-between gap-2">
          <dt>Current group</dt>
          <dd className={isDarkMode ? 'text-gray-200' : 'text-gray-800'}>{user.group || '—'}</dd>
        </div>
        <div className="flex justify-between gap-2">
          <dt>Caller ID</dt>
          <dd className="font-mono">{user.caller_id || '—'}</dd>
        </div>
        <div className="flex justify-between gap-2">
          <dt>Live sessions</dt>
          <dd className="tabular-nums">{formatNumber(user.sessions)}</dd>
        </div>
      </dl>

      <label className="block mb-3">
        <span className={`block text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
          New group
        </span>
        {/* A list rather than a text field. A typed group name that does not
            exist is accepted by RouterOS and the subscriber then authenticates
            against nothing — which presents as "this one customer is offline and
            nobody knows why". The server checks too; this stops it happening. */}
        <select
          className={`${controlClass} w-full`}
          value={group}
          onChange={(event) => setGroup(event.target.value)}
        >
          {!groups.includes(user.group) && user.group && (
            <option value={user.group}>{user.group} (current)</option>
          )}
          {groups.map((name) => (
            <option key={name} value={name}>
              {name}
            </option>
          ))}
        </select>
      </label>

      <div
        className={`flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs ${
          user.sessions > 0
            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
            : isDarkMode
            ? 'bg-gray-800/60 text-gray-400'
            : 'bg-gray-50 text-gray-500'
        }`}
      >
        <AlertTriangle size={14} className="mt-0.5 flex-shrink-0" />
        <span>
          {user.sessions > 0
            ? 'This subscriber is online and keeps the old group’s speed until their session ends. Moving them will offer to re-authorise.'
            : 'This subscriber is offline, so the new group applies on their next connection.'}
        </span>
      </div>
    </Dialog>
  );
};

/**
 * When to re-authorise: now, at a named GMT+8 time, or the next window.
 *
 * Three options rather than two because they answer three different situations:
 * a change being made during a planned outage, one that must land at an agreed
 * time somebody has told customers about, and one that simply should not happen
 * during business hours.
 *
 * The count of affected sessions is repeated on the confirm button. The number
 * is the decision — an operator who reads "Disconnect 340 now" and presses it
 * has made a choice, and one who reads "Confirm" has not.
 */
const ReauthDialog: React.FC<{
  target: { label: string; group?: string; usernames?: string[]; live: number };
  maintenanceNext?: string;
  onClose: () => void;
  onDone: (message: string) => void;
  onError: (message: string) => void;
}> = ({ target, maintenanceNext, onClose, onDone, onError }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [mode, setMode] = useState<'now' | 'at' | 'window'>('at');
  const [at, setAt] = useState(() => manilaInputValue(new Date(Date.now() + 60 * 60 * 1000)));
  const [reason, setReason] = useState('Applied new group settings');
  const [busy, setBusy] = useState(false);

  const payload = {
    ...(target.group ? { group: target.group } : {}),
    ...(target.usernames ? { usernames: target.usernames } : {}),
    reason: reason.trim() || undefined,
  };

  const submit = async () => {
    if (
      mode === 'now' &&
      !window.confirm(
        `Disconnect ${formatNumber(target.live)} live session(s) on "${target.label}" right now?\n\n` +
          'Those subscribers drop immediately and reconnect on the new settings.'
      )
    ) {
      return;
    }

    setBusy(true);

    try {
      if (mode === 'now') {
        const result = await mikrotikService.kickNow(payload);
        onDone(result.message);
      } else if (mode === 'at') {
        // Sent as plain "YYYY-MM-DD HH:MM" with no offset — the server reads it
        // in Asia/Manila. The browser's own timezone is deliberately not
        // consulted: an operator working from elsewhere is still scheduling work
        // in the field, and the field is in GMT+8.
        const result = await mikrotikService.scheduleKick({
          ...payload,
          at: at.replace('T', ' '),
        });
        onDone(result.message);
      } else {
        const result = await mikrotikService.kickLater(payload);
        onDone(result.message);
      }
    } catch (err: any) {
      onError(
        err?.response?.data?.errors?.at?.[0] ??
          err?.response?.data?.message ??
          'The request failed.'
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog
      title={`Re-authorise · ${target.label}`}
      onClose={onClose}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button
            variant={mode === 'now' ? 'danger' : 'primary'}
            onClick={submit}
            disabled={busy || (mode === 'at' && !at)}
            icon={busy ? <Loader2 size={14} className="animate-spin" /> : undefined}
          >
            {mode === 'now'
              ? `Disconnect ${formatNumber(target.live)} now`
              : mode === 'at'
              ? 'Schedule'
              : 'Queue for window'}
          </Button>
        </>
      }
    >
      <div
        className={`flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs mb-4 ${
          isDarkMode ? 'bg-gray-800/60 text-gray-300' : 'bg-gray-50 text-gray-600'
        }`}
      >
        <Server size={14} className="mt-0.5 flex-shrink-0" />
        <span>
          <strong>{formatNumber(target.live)}</strong> live session
          {target.live === 1 ? '' : 's'} on <strong>{target.label}</strong> are still running on the
          previous settings. Disconnecting forces them to authenticate again and pick up the new
          ones.
        </span>
      </div>

      <fieldset className="space-y-2 mb-4">
        <Option
          checked={mode === 'now'}
          onSelect={() => setMode('now')}
          label="Kick all affected now"
          hint="They drop immediately and reconnect within seconds. Expect support calls."
          tone="danger"
        />
        <Option
          checked={mode === 'at'}
          onSelect={() => setMode('at')}
          label="Schedule re-authorisation (GMT+8)"
          hint="Runs at the minute you name, in Asia/Manila. Cancellable until it fires."
        />
        <Option
          checked={mode === 'window'}
          onSelect={() => setMode('window')}
          label="Next maintenance window"
          hint={
            maintenanceNext
              ? `Opens ${maintenanceNext}. Use when the exact minute does not matter.`
              : 'Use when the exact minute does not matter.'
          }
        />
      </fieldset>

      {mode === 'at' && (
        <label className="block mb-3">
          <span className={`block text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            Date and time — {TIMEZONE} (GMT+8)
          </span>
          <input
            type="datetime-local"
            className={`${controlClass} w-full tabular-nums`}
            value={at}
            onChange={(event) => setAt(event.target.value)}
          />
          {/* Stated because a datetime-local field otherwise reads as the
              browser's local time, and an operator abroad would be an hour or
              more out with nothing on screen to tell them. */}
          <span className={`block text-[11px] mt-1 ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
            Read as Manila time regardless of where you are. Seeded an hour ahead.
          </span>
        </label>
      )}

      <label className="block">
        <span className={`block text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
          Reason
        </span>
        <input
          className={`${controlClass} w-full`}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          placeholder="Why these sessions are being cut"
        />
        <span className={`block text-[11px] mt-1 ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
          Kept with the queue row. A customer asking why their connection dropped at 2am deserves a
          better answer than “something on the router”.
        </span>
      </label>
    </Dialog>
  );
};

const Option: React.FC<{
  checked: boolean;
  onSelect: () => void;
  label: string;
  hint: string;
  tone?: 'danger';
}> = ({ checked, onSelect, label, hint, tone }) => {
  const isDarkMode = useTheme();

  return (
    <label
      className={`flex items-start gap-2.5 rounded-lg border px-3 py-2.5 cursor-pointer transition-colors ${
        checked
          ? tone === 'danger'
            ? 'border-red-500/60 bg-red-500/5'
            : 'border-blue-500/60 bg-blue-500/5'
          : isDarkMode
          ? 'border-gray-800 hover:border-gray-700'
          : 'border-gray-200 hover:border-gray-300'
      }`}
    >
      <input
        type="radio"
        checked={checked}
        onChange={onSelect}
        className="mt-0.5 flex-shrink-0"
        name="reauth-mode"
      />
      <span className="min-w-0">
        <span
          className={`block text-sm font-medium ${
            tone === 'danger' && checked
              ? 'text-red-600 dark:text-red-400'
              : isDarkMode
              ? 'text-gray-200'
              : 'text-gray-800'
          }`}
        >
          {label}
        </span>
        <span className={`block text-[11px] ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          {hint}
        </span>
      </span>
    </label>
  );
};

const SearchBox: React.FC<{ value: string; onChange: (value: string) => void }> = ({
  value,
  onChange,
}) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  return (
    <span className="relative">
      <Search
        size={13}
        className={`absolute left-2 top-1/2 -translate-y-1/2 ${
          isDarkMode ? 'text-gray-500' : 'text-gray-400'
        }`}
      />
      <input
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder="Search user, group, caller ID…"
        className={`${controlClass} !pl-7 !py-1 !text-xs w-56`}
        aria-label="Search RADIUS users"
      />
    </span>
  );
};

// ── Rate limit input ──────────────────────────────────────────────────

/** One direction of a rate limit, as the form holds it. */
interface RateParts {
  amount: string;
  unit: 'Kbps' | 'Mbps' | 'Gbps';
}

const RATE_UNITS: RateParts['unit'][] = ['Kbps', 'Mbps', 'Gbps'];

/**
 * Reads one side of a stored RouterOS rate limit back into a number and a unit.
 *
 * The stored value is whatever the router holds — "250M/50M", "512k/512k", or a
 * bare bits-per-second figure. Anything unrecognised opens the form empty rather
 * than guessing: a wrong number pre-filled into a field somebody is about to
 * press Apply on is worse than a blank one.
 *
 * @param side 0 for download, 1 for upload. A single figure is symmetric.
 */
const splitRate = (stored: string, side: 0 | 1): RateParts => {
  const halves = (stored || '').split('/');
  const value = (halves[side] ?? halves[0] ?? '').trim();

  const match = value.match(/^(\d+(?:\.\d+)?)\s*([kKmMgG])?$/);

  if (!match) {
    return { amount: '', unit: 'Mbps' };
  }

  const unit = (match[2] || '').toLowerCase();

  return {
    amount: match[1],
    unit: unit === 'k' ? 'Kbps' : unit === 'g' ? 'Gbps' : 'Mbps',
  };
};

/**
 * The two halves as the string the API parses.
 *
 * Written in the operator's own units — "250mb/50mb" — rather than pre-converted
 * to RouterOS notation here. The server owns that conversion, and doing it in
 * two places is how the browser and the router come to disagree about what
 * 1.5 Gbps means.
 *
 * An empty download returns an empty string, which the caller treats as "leave
 * the rate limit alone".
 */
const composeRate = (down: RateParts, up: RateParts): string => {
  const render = (part: RateParts): string => {
    const amount = part.amount.trim();

    if (amount === '') return '';

    return `${amount}${part.unit.replace('bps', 'b').toLowerCase()}`;
  };

  const rx = render(down);

  if (rx === '') return '';

  const tx = render(up);

  return tx === '' || tx === rx ? rx : `${rx}/${tx}`;
};

/** A number and a unit for one direction. */
const RateField: React.FC<{
  label: string;
  amount: string;
  unit: RateParts['unit'];
  onChange: (parts: RateParts) => void;
  onMatch?: () => void;
  matched?: boolean;
  autoFocus?: boolean;
}> = ({ label, amount, unit, onChange, onMatch, matched, autoFocus }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  return (
    <label className="block">
      <span
        className={`flex items-center justify-between gap-2 text-xs mb-1 ${
          isDarkMode ? 'text-gray-400' : 'text-gray-500'
        }`}
      >
        {label}
        {onMatch && !matched && (
          <button
            type="button"
            onClick={onMatch}
            className={isDarkMode ? 'text-blue-300 hover:underline' : 'text-blue-600 hover:underline'}
          >
            match download
          </button>
        )}
      </span>

      <span className="flex">
        <input
          type="number"
          min={1}
          step="any"
          inputMode="decimal"
          value={amount}
          onChange={(event) => onChange({ amount: event.target.value, unit })}
          placeholder="250"
          autoFocus={autoFocus}
          className={`${controlClass} w-full !rounded-r-none tabular-nums`}
          aria-label={`${label} speed`}
        />
        <select
          value={unit}
          onChange={(event) => onChange({ amount, unit: event.target.value as RateParts['unit'] })}
          className={`${controlClass} !rounded-l-none !border-l-0`}
          aria-label={`${label} unit`}
        >
          {RATE_UNITS.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </span>
    </label>
  );
};

/** Bits per second in the units a person reads — for the preview line only. */
const describeBps = (bps: number): string => {
  if (bps >= 1_000_000_000) return `${(bps / 1_000_000_000).toFixed(bps % 1_000_000_000 ? 2 : 0)} Gbps`;
  if (bps >= 1_000_000) return `${(bps / 1_000_000).toFixed(bps % 1_000_000 ? 2 : 0)} Mbps`;
  if (bps >= 1_000) return `${(bps / 1_000).toFixed(bps % 1_000 ? 2 : 0)} kbps`;

  return `${bps} bps`;
};

export default MikrotikRadius;
