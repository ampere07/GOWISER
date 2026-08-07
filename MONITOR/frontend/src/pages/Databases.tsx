import React, { useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Database,
  Info,
  Loader2,
  Network,
  Pencil,
  Plug,
  Plus,
  Table2,
  Trash2,
  XCircle,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import ConnectionForm from '../components/databases/ConnectionForm';
import SchemaMapPanel from '../components/databases/SchemaMapPanel';
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
} from '../components/reporting/primitives';
import { useTheme } from '../hooks/useTheme';
import { databaseService } from '../services/databaseService';
import {
  ConnectionTestResult,
  DatabaseConnection,
  DatabaseListResponse,
  IntrospectResult,
} from '../types/databases';
import { formatDateTime, formatNumber } from '../utils/format';

interface DatabasesProps {
  refreshToken: number;
}

/** Section keys are snake_case on the wire; these are what an operator reads. */
const SECTION_LABELS: Record<string, string> = {
  subscriber_analytics: 'Subscribers',
  financial: 'Financial',
  operations: 'Operations',
  tech: 'Tech',
  employee: 'Employee',
};

/**
 * Databases — which databases the portal reads, and the credentials to read them.
 *
 * The only screen in MONITOR that writes. Adding a row here makes the database
 * available to every section on the next request, with no deploy: the backend
 * registers the connection with Laravel at runtime.
 *
 * Three things this page deliberately makes visible, because each is a way a
 * misconfiguration hides:
 *
 *  - whether a connection is *reachable*, from a real connect attempt
 *  - whether it is *useful*, from the sections its table structure can actually
 *    serve — a database that connects but matches nothing would otherwise show up
 *    as five empty pages rather than one wrong setting
 *  - whether the portal is running on this table at all, or still on the
 *    .env-defined fallback
 */
const Databases: React.FC<DatabasesProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();

  const [data, setData] = useState<DatabaseListResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ ok: boolean; message: string } | null>(null);

  const [editing, setEditing] = useState<DatabaseConnection | null>(null);
  const [creating, setCreating] = useState(false);
  const [testingId, setTestingId] = useState<number | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<DatabaseConnection | null>(null);
  const [inspecting, setInspecting] = useState<DatabaseConnection | null>(null);
  /** The connection whose data map is open. Only one panel is ever open. */
  const [mapping, setMapping] = useState<DatabaseConnection | null>(null);

  const load = useCallback(() => {
    setLoading(true);

    databaseService
      .list()
      .then((result) => {
        setData(result);
        setError(null);
      })
      .catch((err) => {
        console.error('Failed to load database connections:', err);
        setError(
          err?.response?.status === 403
            ? 'You do not have permission to manage database connections.'
            : err?.response?.data?.message || 'Could not load the database list.'
        );
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load, refreshToken]);

  const connections = data?.connections ?? [];

  const report = (result: ConnectionTestResult, prefix: string) =>
    setNotice({ ok: result.ok, message: `${prefix} ${result.message}` });

  const runTest = (connection: DatabaseConnection) => {
    setTestingId(connection.id);
    setNotice(null);

    databaseService
      .test(connection.id)
      .then((result) => {
        report(result.test, `${connection.label}:`);
        // Refresh the row so last_checked_at and the section list update too.
        load();
      })
      .catch((err) =>
        setNotice({
          ok: false,
          message: err?.response?.data?.message || `Could not reach ${connection.label}.`,
        })
      )
      .finally(() => setTestingId(null));
  };

  const toggleEnabled = (connection: DatabaseConnection) => {
    setBusyId(connection.id);
    setNotice(null);

    // Sent as a full update because the endpoint validates the whole row; the
    // password is omitted, which the backend reads as "leave it alone".
    databaseService
      .update(connection.id, {
        key: connection.key,
        label: connection.label,
        profile_key: connection.profile_key,
        host: connection.host,
        port: connection.port,
        database: connection.database,
        username: connection.username,
        password: '',
        timezone: connection.timezone,
        enabled: !connection.enabled,
        scope_column: connection.scope?.column ?? '',
        scope_value: connection.scope?.value ?? '',
      })
      .then(() => {
        setNotice({
          ok: true,
          message: `${connection.label} ${connection.enabled ? 'disabled' : 'enabled'}.`,
        });
        load();
      })
      .catch((err) =>
        setNotice({
          ok: false,
          message: err?.response?.data?.message || 'Could not save that change.',
        })
      )
      .finally(() => setBusyId(null));
  };

  const move = (index: number, direction: -1 | 1) => {
    const next = [...connections];
    const target = index + direction;

    if (target < 0 || target >= next.length) return;

    [next[index], next[target]] = [next[target], next[index]];

    setBusyId(connections[index].id);

    databaseService
      .reorder(next.map((connection) => connection.id))
      .then(load)
      .catch((err) =>
        setNotice({
          ok: false,
          message: err?.response?.data?.message || 'Could not save the new order.',
        })
      )
      .finally(() => setBusyId(null));
  };

  const remove = (connection: DatabaseConnection) => {
    setBusyId(connection.id);

    databaseService
      .remove(connection.id)
      .then((message) => {
        setNotice({ ok: true, message });
        setConfirmDelete(null);
        load();
      })
      .catch((err) =>
        setNotice({
          ok: false,
          message: err?.response?.data?.message || 'Could not remove that connection.',
        })
      )
      .finally(() => setBusyId(null));
  };

  return (
    <ReportingPage>
      <PageHeader
        title="Databases"
        subtitle="Which databases the portal reads, and the credentials to read them"
        actions={
          <Button
            variant="primary"
            icon={<Plus size={14} />}
            onClick={() => {
              setEditing(null);
              setCreating(true);
              setNotice(null);
            }}
          >
            Add Database
          </Button>
        }
      />

      {error && <ErrorBanner message={error} />}

      {notice && (
        <div
          className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
            notice.ok
              ? isDarkMode
                ? 'border-emerald-800/60 bg-emerald-500/10 text-emerald-200'
                : 'border-emerald-200 bg-emerald-50 text-emerald-800'
              : isDarkMode
              ? 'border-red-800/60 bg-red-500/10 text-red-200'
              : 'border-red-200 bg-red-50 text-red-800'
          }`}
        >
          {notice.ok ? (
            <CheckCircle2 size={15} className="mt-0.5 flex-shrink-0" />
          ) : (
            <AlertTriangle size={15} className="mt-0.5 flex-shrink-0" />
          )}
          <span className="break-words">{notice.message}</span>
        </div>
      )}

      {/* An empty list is ambiguous on its own. Say what it means. */}
      {data?.no_sources && (
        <div
          className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
            isDarkMode
              ? 'border-amber-800/60 bg-amber-500/10 text-amber-200'
              : 'border-amber-200 bg-amber-50 text-amber-800'
          }`}
        >
          <Info size={15} className="mt-0.5 flex-shrink-0" />
          <span>
            No database is connected yet, so every reporting page will be empty. This is the only
            place monitored databases are defined — add one below and it becomes available across the
            portal on the next request.
          </span>
        </div>
      )}

      {(creating || editing) && (
        <ConnectionForm
          connection={editing}
          profiles={data?.profiles ?? []}
          onCancel={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSaved={(result, message) => {
            setCreating(false);
            setEditing(null);
            report(result.test, message);
            load();
          }}
        />
      )}

      <Card flush>
        <CardHeader
          title="Connections"
          subtitle="Order sets which database the portal opens on by default"
          icon={<Database size={16} />}
        />
        <Table>
          <Thead>
            <Th width="70px">Order</Th>
            <Th>Database</Th>
            <Th>Structure</Th>
            <Th>Serves</Th>
            <Th>Last check</Th>
            <Th align="right">Actions</Th>
          </Thead>
          <tbody>
            <TableState
              colSpan={6}
              loading={loading && connections.length === 0}
              error={error}
              empty={connections.length === 0}
              emptyMessage="No databases configured. Add one to get started."
            />

            {connections.map((connection, index) => (
              <Tr key={connection.id} className={connection.enabled ? '' : 'opacity-60'}>
                <Td>
                  <div className="flex items-center gap-0.5">
                    <button
                      type="button"
                      onClick={() => move(index, -1)}
                      disabled={index === 0 || busyId !== null}
                      aria-label="Move up"
                      className={`p-0.5 rounded disabled:opacity-30 ${
                        isDarkMode ? 'hover:bg-gray-800' : 'hover:bg-gray-100'
                      }`}
                    >
                      <ChevronUp size={14} />
                    </button>
                    <button
                      type="button"
                      onClick={() => move(index, 1)}
                      disabled={index === connections.length - 1 || busyId !== null}
                      aria-label="Move down"
                      className={`p-0.5 rounded disabled:opacity-30 ${
                        isDarkMode ? 'hover:bg-gray-800' : 'hover:bg-gray-100'
                      }`}
                    >
                      <ChevronDown size={14} />
                    </button>
                  </div>
                </Td>

                <Td>
                  <div className="flex items-center gap-2">
                    <StatusDot status={connection.last_status} enabled={connection.enabled} />
                    <div className="min-w-0">
                      <p className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                        {connection.label}
                        {index === 0 && connection.enabled && (
                          <span className="ml-2 align-middle">
                            <Pill tone="info">default</Pill>
                          </span>
                        )}
                      </p>
                      <p className={`text-xs font-mono ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                        {connection.username}@{connection.host}:{connection.port}/{connection.database}
                      </p>
                      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        key: {connection.key}
                        {connection.scope && (
                          <>
                            {' '}
                            · scoped to {connection.scope.column}={connection.scope.value}
                          </>
                        )}
                        {!connection.has_password && (
                          <span className="ml-1 text-amber-500">· no password saved</span>
                        )}
                      </p>
                    </div>
                  </div>
                </Td>

                <Td className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                  {connection.profile_label}
                </Td>

                <Td>
                  {/* What the portal can actually report from this database. An
                      empty list on a reachable connection is the signal that the
                      table structure is not what was expected. */}
                  {connection.sections.length === 0 ? (
                    <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                      {connection.enabled ? 'nothing yet' : 'disabled'}
                    </span>
                  ) : (
                    <div className="flex flex-wrap gap-1">
                      {connection.sections.map((section) => (
                        <Pill key={section} tone="neutral">
                          {SECTION_LABELS[section] ?? section}
                        </Pill>
                      ))}
                    </div>
                  )}
                </Td>

                <Td>
                  {connection.last_checked_at ? (
                    <>
                      <p className={`text-xs ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                        {formatDateTime(connection.last_checked_at)}
                      </p>
                      {connection.last_error && (
                        <p
                          className="text-xs text-red-500 break-words max-w-[260px]"
                          title={connection.last_error}
                        >
                          {connection.last_error}
                        </p>
                      )}
                    </>
                  ) : (
                    <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                      never tested
                    </span>
                  )}
                </Td>

                <Td align="right">
                  <div className="inline-flex items-center gap-1.5">
                    <Button
                      variant="outline"
                      icon={
                        testingId === connection.id ? (
                          <Loader2 size={14} className="animate-spin" />
                        ) : (
                          <Plug size={14} />
                        )
                      }
                      onClick={() => runTest(connection)}
                      disabled={testingId !== null || busyId !== null}
                      title="Try connecting now"
                    />
                    <Button
                      variant="outline"
                      icon={<Table2 size={14} />}
                      onClick={() => {
                        setMapping(null);
                        setInspecting(connection);
                      }}
                      disabled={!connection.enabled}
                      title={
                        connection.enabled
                          ? 'Inspect tables'
                          : 'Enable this connection before inspecting it'
                      }
                    />

                    {/* Distinct from the inspector beside it, and worth its own
                        button: that one lists what the database *has*, this one
                        shows what the portal *reads* — which metric card each
                        table feeds and how the tables reach one another. The
                        first answers "is this the right database", the second
                        answers "why is that figure wrong". */}
                    <Button
                      variant="outline"
                      icon={<Network size={14} />}
                      onClick={() => {
                        setInspecting(null);
                        setMapping(connection);
                      }}
                      disabled={!connection.enabled}
                      title={
                        connection.enabled
                          ? 'Show which metric cards this database feeds'
                          : 'Enable this connection before mapping it'
                      }
                    />
                    <Button
                      variant="outline"
                      icon={<Pencil size={14} />}
                      onClick={() => {
                        setCreating(false);
                        setEditing(connection);
                        setNotice(null);
                      }}
                      title="Edit"
                    />
                    <Button
                      variant="outline"
                      onClick={() => toggleEnabled(connection)}
                      disabled={busyId !== null}
                      title={connection.enabled ? 'Disable' : 'Enable'}
                    >
                      {connection.enabled ? 'On' : 'Off'}
                    </Button>
                    <Button
                      variant="danger"
                      icon={<Trash2 size={14} />}
                      onClick={() => setConfirmDelete(connection)}
                      disabled={busyId !== null}
                      title="Remove from MONITOR"
                    />
                  </div>
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>

      {inspecting && (
        <TableInspector connection={inspecting} onClose={() => setInspecting(null)} />
      )}

      {mapping && <SchemaMapPanel connection={mapping} onClose={() => setMapping(null)} />}

      {confirmDelete && (
        <Card>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <p className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                Remove {confirmDelete.label}?
              </p>
              {/* Precise wording on purpose: "delete" on a page of production
                  databases is a word worth being unambiguous about. */}
              <p className={`text-sm mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
                This removes MONITOR's record of the database and its stored credential. The database
                itself, and everything in it, is untouched. Any page currently reading it will fall
                back to the next enabled connection.
              </p>
            </div>
            <div className="flex items-center gap-2 flex-shrink-0">
              <Button variant="outline" onClick={() => setConfirmDelete(null)}>
                Cancel
              </Button>
              <Button
                variant="danger"
                icon={<Trash2 size={14} />}
                onClick={() => remove(confirmDelete)}
                disabled={busyId !== null}
              >
                Remove
              </Button>
            </div>
          </div>
        </Card>
      )}

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Credentials are encrypted before they are stored and are never sent back to this page. Use an
        account with SELECT only — MONITOR refuses to issue anything but a read, but a read-only grant
        is the guarantee that does not depend on this application being correct.
      </p>
    </ReportingPage>
  );
};

/**
 * Reachability at a glance: green connected, red failed, grey never tested.
 *
 * The icon carries a title and an accessible label rather than colour alone —
 * "the green one is fine" is not a distinction every reader can make.
 */
const StatusDot: React.FC<{ status: string | null; enabled: boolean }> = ({ status, enabled }) => {
  const { Icon, tone, label } = !enabled
    ? { Icon: XCircle, tone: 'text-gray-400', label: 'Disabled' }
    : status === 'ok'
    ? { Icon: CheckCircle2, tone: 'text-emerald-500', label: 'Connected' }
    : status === 'failed'
    ? { Icon: AlertTriangle, tone: 'text-red-500', label: 'Connection failed' }
    : { Icon: Database, tone: 'text-gray-400', label: 'Never tested' };

  return (
    <span title={label} aria-label={label} role="img" className="flex-shrink-0">
      <Icon size={16} className={tone} />
    </span>
  );
};

/**
 * Tables the connection can see, and the columns of one of them.
 *
 * The point is confirmation: "1,204 rows in `transactions`" proves the credential
 * reaches the database someone meant, which "Connected" does not.
 */
const TableInspector: React.FC<{ connection: DatabaseConnection; onClose: () => void }> = ({
  connection,
  onClose,
}) => {
  const isDarkMode = useTheme();

  const [result, setResult] = useState<IntrospectResult | null>(null);
  const [columns, setColumns] = useState<IntrospectResult | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setColumns(null);

    databaseService
      .introspect(connection.id)
      .then((data) => {
        if (!cancelled) {
          setResult(data);
          setError(null);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err?.response?.data?.message || 'Could not read this database.');
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [connection.id]);

  const openTable = (table: string) => {
    setColumns(null);

    databaseService
      .introspect(connection.id, table)
      .then(setColumns)
      .catch((err) =>
        setError(err?.response?.data?.message || `Could not read ${table}.`)
      );
  };

  return (
    <Card flush>
      <CardHeader
        title={`Tables — ${connection.label}`}
        subtitle={`${connection.database} on ${connection.host}`}
        icon={<Table2 size={16} />}
        actions={
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
        }
      />
      <CardBody>
        {loading ? (
          <p className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            <Loader2 size={14} className="inline animate-spin mr-2" />
            Reading tables…
          </p>
        ) : error ? (
          <p className="text-sm text-red-500">{error}</p>
        ) : (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <p className={`text-xs mb-2 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                {formatNumber(result?.tables?.length ?? 0)} tables visible — click one to see its
                columns
              </p>
              <div className="max-h-[320px] overflow-y-auto custom-scroll">
                {(result?.tables ?? []).map((table) => (
                  <button
                    key={table.name}
                    type="button"
                    onClick={() => openTable(table.name)}
                    className={`w-full flex items-center justify-between gap-3 px-2 py-1.5 rounded text-left text-sm ${
                      columns?.table === table.name
                        ? isDarkMode
                          ? 'bg-blue-500/15 text-blue-200'
                          : 'bg-blue-50 text-blue-800'
                        : isDarkMode
                        ? 'hover:bg-gray-800 text-gray-300'
                        : 'hover:bg-gray-50 text-gray-700'
                    }`}
                  >
                    <span className="font-mono truncate">{table.name}</span>
                    {/* information_schema row counts are estimates on InnoDB, so
                        they are labelled as approximate rather than presented as
                        a count someone might reconcile against. */}
                    <span
                      className={`text-xs flex-shrink-0 ${
                        isDarkMode ? 'text-gray-500' : 'text-gray-400'
                      }`}
                    >
                      ~{formatNumber(table.approx_rows)}
                    </span>
                  </button>
                ))}
              </div>
            </div>

            <div>
              {columns?.columns ? (
                <>
                  <p className={`text-xs mb-2 font-mono ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                    {columns.table}
                  </p>
                  <div className="max-h-[320px] overflow-y-auto custom-scroll">
                    {columns.columns.map((column) => (
                      <div
                        key={column.name}
                        className={`flex items-center justify-between gap-3 px-2 py-1 text-sm ${
                          isDarkMode ? 'text-gray-300' : 'text-gray-700'
                        }`}
                      >
                        <span className="font-mono truncate">{column.name}</span>
                        <span
                          className={`text-xs flex-shrink-0 ${
                            isDarkMode ? 'text-gray-500' : 'text-gray-400'
                          }`}
                        >
                          {column.type}
                          {column.nullable ? ' · null' : ''}
                        </span>
                      </div>
                    ))}
                  </div>
                </>
              ) : (
                <p className={`text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                  Pick a table to see its columns.
                </p>
              )}
            </div>
          </div>
        )}
      </CardBody>
    </Card>
  );
};

export default Databases;
