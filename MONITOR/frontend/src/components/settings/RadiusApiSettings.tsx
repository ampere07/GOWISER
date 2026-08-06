import React, { useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  Check,
  Loader2,
  Lock,
  Plug,
  Plus,
  Radio,
  Trash2,
  X,
} from 'lucide-react';
import Card, { CardHeader, CardBody } from '../reporting/Card';
import { Button, ErrorBanner, Pill, useControlClass } from '../reporting/primitives';
import { usePermissions } from '../../hooks/usePermissions';
import { useTheme } from '../../hooks/useTheme';
import { radiusConfigService } from '../../services/mikrotikService';
import {
  RadiusConfigList,
  RadiusProbeResult,
  RadiusServerConfig,
  RadiusServerFormValues,
} from '../../types/mikrotik';
import { ACTION } from '../../types/rbac';

const emptyServer: RadiusServerFormValues = {
  ssl_type: 'https',
  ip: '',
  port: '443',
  username: '',
  password: '',
  label: '',
  is_active: true,
};

/**
 * RADIUS API Settings — where MikroTik RADIUS points.
 *
 * Ported from GOWISER's own RADIUS configuration screen: the same fields, the
 * same two-server cap, the same #1/#2 failover order, so an operator who has
 * configured one system is filling in a familiar form rather than learning a
 * second schema.
 *
 * ── What this screen deliberately cannot do ───────────────────────────
 *
 * It cannot show you a password. The API returns `has_password` and never the
 * secret, so the field is always blank and always optional on an edit — leaving
 * it blank keeps whatever is stored. A form that echoed the password back would
 * put it through every proxy between here and the server on every unrelated
 * edit, and would be submitted again each time somebody corrected a typo in the
 * label.
 *
 * ── Why the test button authenticates ─────────────────────────────────
 *
 * A router that accepts TCP but rejects the credentials is not a working
 * configuration. "The port is open" is precisely the answer that sends an
 * operator looking in the wrong place for an afternoon, so the check performs a
 * real authenticated read and reports what came back.
 *
 * Behind `action.radius.manage`, which is separate from the branding grant on
 * the rest of the Settings page: these rows hold credentials for hardware that
 * can take the network offline, and they have no business sharing a permission
 * with the colour palette.
 */
const RadiusApiSettings: React.FC<{ refreshToken: number }> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();
  const { can } = usePermissions();

  const editable = can(ACTION.radiusManage);

  const [data, setData] = useState<RadiusConfigList | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const [editing, setEditing] = useState<RadiusServerConfig | null>(null);
  const [form, setForm] = useState<RadiusServerFormValues | null>(null);
  const [probes, setProbes] = useState<Record<number, RadiusProbeResult>>({});

  const load = useCallback(() => {
    setLoading(true);

    radiusConfigService
      .list()
      .then((result) => {
        setData(result);
        setError(null);
      })
      .catch((err) => {
        // A role without the grant gets a 403 here and simply does not see the
        // card — this is not an error worth a red banner on a page whose other
        // sections it can legitimately read.
        if (err?.response?.status === 403) {
          setData(null);
          setError(null);
          return;
        }

        setError(err?.response?.data?.message ?? 'Unable to load the RADIUS configuration.');
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => load(), [load, refreshToken]);

  const run = async (key: string, action: () => Promise<unknown>, message: string) => {
    setBusy(key);
    setError(null);
    setNotice(null);

    try {
      await action();
      setNotice(message);
      load();
    } catch (err: any) {
      const errors = err?.response?.data?.errors;
      const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;

      setError(first ?? err?.response?.data?.message ?? 'That change could not be saved.');
    } finally {
      setBusy(null);
    }
  };

  const save = () => {
    if (!form) return;

    if (!form.ip.trim()) {
      setError('Enter the RADIUS host — an IP address or a hostname, without the scheme.');
      return;
    }

    if (!editing && !form.password.trim()) {
      setError('A password is required when adding a server.');
      return;
    }

    run(
      'server',
      async () => {
        if (editing) {
          // The password is only sent when it was actually typed. See the note
          // above the component: blank means "leave it alone".
          const { password, ...rest } = form;
          await radiusConfigService.update(
            editing.id,
            password.trim() ? { ...rest, password } : rest
          );
        } else {
          await radiusConfigService.create(form);
        }

        setForm(null);
        setEditing(null);
      },
      editing ? 'RADIUS server updated.' : 'RADIUS server added.'
    );
  };

  const test = async (server: RadiusServerConfig) => {
    setBusy(`test-${server.id}`);
    setError(null);

    try {
      const result = await radiusConfigService.test(server.id);

      setProbes((current) => ({ ...current, [server.id]: result }));
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'The connectivity check could not be run.');
    } finally {
      setBusy(null);
    }
  };

  // No grant, no card. Unlike the branding sections, there is nothing useful to
  // show read-only here — the interesting half is the credentials, which are
  // never sent, so a read-only version would be four hostnames and no context.
  if (!editable && !data) {
    return null;
  }

  const servers = data?.servers ?? [];
  const atCapacity = servers.length >= (data?.max_servers ?? 2);

  return (
    <Card flush>
      <CardHeader
        title="RADIUS API"
        subtitle="Where MikroTik RADIUS reads groups and sends disconnects"
        icon={<Radio size={16} />}
        actions={
          data && (
            <Pill tone={data.source === 'settings' ? 'success' : 'warning'}>
              {data.source === 'settings' ? 'managed here' : 'using environment'}
            </Pill>
          )
        }
      />

      <CardBody>
        {error && (
          <div className="mb-4">
            <ErrorBanner message={error} />
          </div>
        )}

        {notice && (
          <div className="mb-4 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center gap-3 text-sm">
            <Check size={16} className="flex-shrink-0" />
            {notice}
          </div>
        )}

        {/* The environment fallback is a real state and is stated as one: a
            deployment that has never opened this screen is still working, and an
            operator who sees an empty list needs to know the module has not
            stopped. */}
        {data?.source === 'environment' && (
          <div
            className={`mb-4 flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
              isDarkMode
                ? 'border-amber-800/60 bg-amber-500/10 text-amber-200'
                : 'border-amber-200 bg-amber-50 text-amber-800'
            }`}
          >
            <AlertTriangle size={15} className="mt-0.5 flex-shrink-0" />
            <span>
              {data.configured
                ? 'No server is configured here, so the MIKROTIK_* environment variables are in use. Adding one below takes over from them entirely.'
                : 'No RADIUS server is configured, here or in the environment. MikroTik RADIUS has nothing to talk to until one is added.'}
            </span>
          </div>
        )}

        {loading && !data ? (
          <div className="flex items-center justify-center py-8">
            <Loader2 size={20} className="animate-spin text-gray-400" />
          </div>
        ) : (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {servers.map((server) => (
              <ServerCard
                key={server.id}
                server={server}
                editable={editable}
                busy={busy === `test-${server.id}` || busy === `remove-${server.id}`}
                probe={probes[server.id]}
                onTest={() => test(server)}
                onEdit={() => {
                  setEditing(server);
                  setForm({
                    ssl_type: server.ssl_type,
                    ip: server.ip,
                    port: server.port,
                    username: server.username,
                    password: '',
                    label: server.label ?? '',
                    is_active: server.is_active,
                  });
                  setNotice(null);
                }}
                onDelete={() => {
                  if (
                    !window.confirm(
                      `Remove ${server.base_url}?\n\nMikroTik RADIUS will stop using this server immediately.`
                    )
                  ) {
                    return;
                  }

                  run(
                    `remove-${server.id}`,
                    () => radiusConfigService.remove(server.id),
                    'RADIUS server removed.'
                  );
                }}
              />
            ))}

            {editable && !atCapacity && (
              <button
                type="button"
                onClick={() => {
                  setEditing(null);
                  setForm(emptyServer);
                  setNotice(null);
                }}
                className={`rounded-xl border border-dashed flex flex-col items-center justify-center gap-2 p-6 transition-colors ${
                  isDarkMode
                    ? 'border-gray-700 hover:border-gray-600 hover:bg-gray-900'
                    : 'border-gray-300 hover:border-gray-400 hover:bg-gray-50'
                }`}
                style={{ minHeight: 170 }}
              >
                <span
                  className={`w-9 h-9 rounded-full flex items-center justify-center ${
                    isDarkMode ? 'bg-gray-800 text-gray-400' : 'bg-gray-100 text-gray-500'
                  }`}
                >
                  <Plus size={18} />
                </span>
                <span className={`text-sm font-medium ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                  Add RADIUS Server
                </span>
                <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                  {servers.length === 0 ? 'Primary' : 'Secondary'} · #{servers.length + 1}
                </span>
              </button>
            )}
          </div>
        )}

        <p className={`mt-4 text-xs leading-relaxed ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          Up to {data?.max_servers ?? 2} servers, tried in order: a read is served by the first that
          answers, and a change is applied to the server the record was found on — User Manager ids
          are per-server, so a group id read from the secondary means nothing on the primary.
          Passwords are encrypted at rest and never sent back to this screen; leave the field blank
          when editing to keep the stored one.
        </p>
      </CardBody>

      {/* ── Editor ───────────────────────────────────────────────────── */}
      {form && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
          <div
            className={`w-full max-w-md rounded-xl border max-h-[90vh] overflow-y-auto ${
              isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'
            }`}
          >
            <div
              className={`flex items-center justify-between gap-3 px-5 py-3.5 border-b ${
                isDarkMode ? 'border-gray-800' : 'border-gray-200'
              }`}
            >
              <h3 className={`font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {editing ? `Edit RADIUS #${editing.position}` : 'Add RADIUS server'}
              </h3>
              <button
                type="button"
                onClick={() => {
                  setForm(null);
                  setEditing(null);
                }}
                className={isDarkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-black'}
              >
                <X size={18} />
              </button>
            </div>

            <CardBody>
              <Field label="Label" hint="What the screens call this server">
                <input
                  className={`${controlClass} w-full`}
                  value={form.label}
                  onChange={(event) => setForm({ ...form, label: event.target.value })}
                  placeholder="Core RADIUS"
                />
              </Field>

              <div className="grid grid-cols-3 gap-2">
                <Field label="Scheme">
                  <select
                    className={`${controlClass} w-full`}
                    value={form.ssl_type}
                    onChange={(event) =>
                      setForm({
                        ...form,
                        ssl_type: event.target.value as 'http' | 'https',
                        // Follows the scheme unless the operator has already
                        // typed something non-default, which is almost always a
                        // deliberate choice worth keeping.
                        port:
                          form.port === '443' || form.port === '80'
                            ? event.target.value === 'https'
                              ? '443'
                              : '80'
                            : form.port,
                      })
                    }
                  >
                    <option value="https">https</option>
                    <option value="http">http</option>
                  </select>
                </Field>

                <Field label="Host" className="col-span-1">
                  <input
                    className={`${controlClass} w-full font-mono !text-xs`}
                    value={form.ip}
                    onChange={(event) => setForm({ ...form, ip: event.target.value })}
                    placeholder="10.0.0.2"
                  />
                </Field>

                <Field label="Port">
                  <input
                    className={`${controlClass} w-full font-mono !text-xs tabular-nums`}
                    value={form.port}
                    onChange={(event) => setForm({ ...form, port: event.target.value })}
                    placeholder="443"
                    inputMode="numeric"
                  />
                </Field>
              </div>

              <Field
                label="Username"
                hint="An account with only the permissions this feature needs"
              >
                <input
                  className={`${controlClass} w-full`}
                  value={form.username}
                  onChange={(event) => setForm({ ...form, username: event.target.value })}
                  placeholder="monitor-svc"
                  autoComplete="off"
                />
              </Field>

              <Field
                label="Password"
                hint={
                  editing
                    ? 'Leave blank to keep the stored password'
                    : 'Stored encrypted and never shown again'
                }
              >
                <input
                  type="password"
                  className={`${controlClass} w-full`}
                  value={form.password}
                  onChange={(event) => setForm({ ...form, password: event.target.value })}
                  placeholder={editing ? '••••••••' : ''}
                  autoComplete="new-password"
                />
              </Field>

              <label className="flex items-center gap-2 mt-3 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
                  className="rounded"
                />
                <span className={`text-sm ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                  In failover rotation
                </span>
              </label>
              <p className={`text-[11px] mt-1 ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
                Turn this off to take a server out of use during maintenance without deleting its
                configuration.
              </p>

              <div className="mt-4 p-2.5 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-600 dark:text-amber-400 flex items-start gap-2 text-xs">
                <AlertTriangle size={14} className="mt-0.5 flex-shrink-0" />
                Enter the host on its own — no scheme and no path. The scheme and port are the
                fields beside it, and a full URL here would be concatenated onto them.
              </div>
            </CardBody>

            <div
              className={`flex justify-end gap-2 px-5 py-3 border-t ${
                isDarkMode ? 'border-gray-800' : 'border-gray-200'
              }`}
            >
              <Button
                variant="outline"
                onClick={() => {
                  setForm(null);
                  setEditing(null);
                }}
              >
                Cancel
              </Button>
              <Button
                variant="primary"
                onClick={save}
                disabled={busy === 'server'}
                icon={
                  busy === 'server' ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />
                }
              >
                Save
              </Button>
            </div>
          </div>
        </div>
      )}
    </Card>
  );
};

/** One configured endpoint, with its last connectivity result. */
const ServerCard: React.FC<{
  server: RadiusServerConfig;
  editable: boolean;
  busy: boolean;
  probe?: RadiusProbeResult;
  onTest: () => void;
  onEdit: () => void;
  onDelete: () => void;
}> = ({ server, editable, busy, probe, onTest, onEdit, onDelete }) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`rounded-xl border p-4 ${
        isDarkMode ? 'border-gray-800 bg-gray-950/40' : 'border-gray-200 bg-gray-50/70'
      }`}
    >
      <div className="flex items-start justify-between gap-2 mb-3">
        <div className="min-w-0">
          <p className={`font-semibold truncate ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
            {server.label || `RADIUS #${server.position}`}
          </p>
          <p className={`text-xs font-mono truncate ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            {server.base_url}
          </p>
        </div>

        <div className="flex items-center gap-1 flex-shrink-0">
          <Pill tone={server.position === 1 ? 'info' : 'neutral'}>#{server.position}</Pill>
          {!server.is_active && <Pill tone="warning">paused</Pill>}
        </div>
      </div>

      <dl className="space-y-1 text-xs mb-3">
        <Row label="User" value={server.username} mono />
        <Row
          label="Password"
          value={server.has_password ? 'stored' : 'not set'}
          tone={server.has_password ? undefined : 'warning'}
        />
        <Row label="Changed by" value={server.updated_by ?? '—'} />
        <Row label="Changed" value={server.updated_at ?? '—'} />
      </dl>

      {/* Only shown once a check has been run: a card that opened claiming
          "unknown" for every server would train people to ignore the line. */}
      {probe && (
        <div
          className={`flex items-start gap-2 rounded-lg px-2.5 py-2 mb-3 text-xs ${
            probe.online
              ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
              : 'bg-red-500/10 text-red-600 dark:text-red-400'
          }`}
        >
          {probe.online ? (
            <Check size={13} className="mt-0.5 flex-shrink-0" />
          ) : (
            <AlertTriangle size={13} className="mt-0.5 flex-shrink-0" />
          )}
          <span className="min-w-0 break-words">
            {probe.online ? `Authenticated in ${probe.latency_ms} ms.` : probe.error}
          </span>
        </div>
      )}

      <div className="flex flex-wrap gap-1.5">
        <Button
          icon={busy ? <Loader2 size={13} className="animate-spin" /> : <Plug size={13} />}
          onClick={onTest}
          disabled={busy}
          title="Authenticate against this server and report what came back"
        >
          Test
        </Button>

        {editable && (
          <>
            <Button onClick={onEdit} disabled={busy}>
              Edit
            </Button>
            <Button variant="danger" icon={<Trash2 size={13} />} onClick={onDelete} disabled={busy}>
              Remove
            </Button>
          </>
        )}

        {!editable && (
          <span
            className={`inline-flex items-center gap-1 text-xs ${
              isDarkMode ? 'text-gray-600' : 'text-gray-400'
            }`}
          >
            <Lock size={12} /> read-only
          </span>
        )}
      </div>
    </div>
  );
};

const Row: React.FC<{ label: string; value: string; mono?: boolean; tone?: 'warning' }> = ({
  label,
  value,
  mono,
  tone,
}) => {
  const isDarkMode = useTheme();

  return (
    <div className="flex items-baseline justify-between gap-2">
      <dt className={isDarkMode ? 'text-gray-500' : 'text-gray-500'}>{label}</dt>
      <dd
        className={`truncate ${mono ? 'font-mono' : ''} ${
          tone === 'warning'
            ? 'text-amber-600 dark:text-amber-400'
            : isDarkMode
            ? 'text-gray-300'
            : 'text-gray-700'
        }`}
      >
        {value}
      </dd>
    </div>
  );
};

const Field: React.FC<{
  label: string;
  hint?: string;
  className?: string;
  children: React.ReactNode;
}> = ({ label, hint, className = '', children }) => {
  const isDarkMode = useTheme();

  return (
    <label className={`block mb-3 ${className}`}>
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

export default RadiusApiSettings;
