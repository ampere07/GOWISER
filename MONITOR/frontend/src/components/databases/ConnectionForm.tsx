import React, { useState } from 'react';
import { Loader2, Save, X } from 'lucide-react';
import Card, { CardHeader, CardBody } from '../reporting/Card';
import { Button, useControlClass } from '../reporting/primitives';
import { useTheme } from '../../hooks/useTheme';
import { databaseService } from '../../services/databaseService';
import {
  ConnectionFormValues,
  ConnectionTestResult,
  DatabaseConnection,
  SchemaProfileOption,
  ValidationErrors,
} from '../../types/databases';

interface ConnectionFormProps {
  /** Null to add a new connection. */
  connection: DatabaseConnection | null;
  profiles: SchemaProfileOption[];
  onCancel: () => void;
  onSaved: (result: { connection: DatabaseConnection; test: ConnectionTestResult }, message: string) => void;
}

/** A slug the backend will accept, derived from the label as a starting point. */
const slugify = (value: string): string =>
  value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64);

const blank = (profiles: SchemaProfileOption[]): ConnectionFormValues => ({
  key: '',
  label: '',
  // Defaults to the first profile so the commonest case — another branch of the
  // same platform — is one fewer decision.
  profile_key: profiles[0]?.key ?? '',
  host: '127.0.0.1',
  port: 3306,
  database: '',
  username: '',
  password: '',
  timezone: '+08:00',
  enabled: true,
  scope_column: '',
  scope_value: '',
});

const fromConnection = (connection: DatabaseConnection): ConnectionFormValues => ({
  key: connection.key,
  label: connection.label,
  profile_key: connection.profile_key,
  host: connection.host,
  port: connection.port,
  database: connection.database,
  username: connection.username,
  // Always blank: the API never returns a stored credential, and an empty value
  // means "leave it unchanged".
  password: '',
  timezone: connection.timezone,
  enabled: connection.enabled,
  scope_column: connection.scope?.column ?? '',
  scope_value: connection.scope?.value ?? '',
});

/**
 * Add or edit one database connection.
 *
 * Saving also tests the connection, and the result is reported back to the page.
 * Splitting those apart would let someone save six fields, see "saved", and only
 * discover on another screen that the host was wrong.
 */
const ConnectionForm: React.FC<ConnectionFormProps> = ({
  connection,
  profiles,
  onCancel,
  onSaved,
}) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [values, setValues] = useState<ConnectionFormValues>(() =>
    connection ? fromConnection(connection) : blank(profiles)
  );
  const [errors, setErrors] = useState<ValidationErrors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [showScope, setShowScope] = useState(Boolean(connection?.scope));

  const isNew = connection === null;

  const set = <K extends keyof ConnectionFormValues>(field: K, value: ConnectionFormValues[K]) => {
    setValues((current) => ({ ...current, [field]: value }));

    // Clear the field's error as soon as it is touched, so a stale message does
    // not sit under a field the user has already fixed.
    setErrors((current) => {
      if (!current[field as string]) return current;

      const next = { ...current };
      delete next[field as string];

      return next;
    });
  };

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    setMessage(null);

    const request = isNew
      ? databaseService.create(values)
      : databaseService.update(connection!.id, values);

    request
      .then((result) =>
        onSaved(result, isNew ? `Added ${result.connection.label}.` : `Saved ${result.connection.label}.`)
      )
      .catch((err) => {
        if (err?.response?.status === 422) {
          setErrors(err.response.data?.errors ?? {});
          setMessage(err.response.data?.message ?? 'Please check the highlighted fields.');
        } else {
          setMessage(err?.response?.data?.message || 'Could not save this connection.');
        }
      })
      .finally(() => setSaving(false));
  };

  const fieldError = (field: string): string | null => errors[field]?.[0] ?? null;

  const selectedProfile = profiles.find((profile) => profile.key === values.profile_key);

  return (
    <Card flush>
      <CardHeader
        title={isNew ? 'Add Database' : `Edit ${connection!.label}`}
        subtitle={
          isNew
            ? 'The connection is tested as soon as you save'
            : 'Leave the password blank to keep the stored one'
        }
        actions={
          <Button variant="outline" icon={<X size={14} />} onClick={onCancel} title="Cancel" />
        }
      />
      <CardBody>
        <form onSubmit={submit} className="space-y-4">
          {message && (
            <p className="text-sm text-red-500 break-words">{message}</p>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <Field label="Label" error={fieldError('label')} hint="Shown in the source switcher">
              <input
                type="text"
                value={values.label}
                onChange={(event) => {
                  const label = event.target.value;
                  set('label', label);

                  // Only auto-fill the key while adding, and only until the user
                  // types their own. Changing it on an existing row would break
                  // any bookmarked ?source= link.
                  if (isNew && (values.key === '' || values.key === slugify(values.label))) {
                    set('key', slugify(label));
                  }
                }}
                className={`${controlClass} w-full`}
                placeholder="Isabela Branch"
                required
                autoFocus
              />
            </Field>

            <Field
              label="Key"
              error={fieldError('key')}
              hint={isNew ? 'Used in links; lowercase, no spaces' : 'Changing this breaks saved links'}
            >
              <input
                type="text"
                value={values.key}
                onChange={(event) => set('key', slugify(event.target.value))}
                className={`${controlClass} w-full font-mono`}
                placeholder="isabela"
                required
              />
            </Field>

            {/* Not "which database" — this says which system's *table layout*
                this server uses, which is what picks the queries to run against
                it. Every branch running a copy of the same platform picks the
                same value here regardless of its host or database name. */}
            <Field
              label="Table structure"
              error={fieldError('profile_key')}
              hint={selectedProfile?.description ?? 'Which platform this database is a copy of'}
            >
              <select
                value={values.profile_key}
                onChange={(event) => set('profile_key', event.target.value)}
                className={`${controlClass} w-full`}
                required
              >
                {profiles.map((profile) => (
                  <option key={profile.key} value={profile.key}>
                    {profile.label}
                  </option>
                ))}
              </select>
            </Field>

            <Field
              label="Host"
              error={fieldError('host')}
              hint="This server's own IP or hostname"
            >
              <input
                type="text"
                value={values.host}
                onChange={(event) => set('host', event.target.value)}
                className={`${controlClass} w-full font-mono`}
                placeholder="10.0.0.14"
                required
              />
            </Field>

            <Field label="Port" error={fieldError('port')}>
              <input
                type="number"
                value={values.port}
                onChange={(event) => set('port', Number(event.target.value))}
                className={`${controlClass} w-full`}
                min={1}
                max={65535}
                required
              />
            </Field>

            <Field
              label="Database"
              error={fieldError('database')}
              hint="The database name on that server"
            >
              <input
                type="text"
                value={values.database}
                onChange={(event) => set('database', event.target.value)}
                className={`${controlClass} w-full font-mono`}
                placeholder="gowiser_isabela"
                required
              />
            </Field>

            <Field
              label="Username"
              error={fieldError('username')}
              hint="Use an account with SELECT only"
            >
              <input
                type="text"
                value={values.username}
                onChange={(event) => set('username', event.target.value)}
                className={`${controlClass} w-full font-mono`}
                placeholder="monitor_ro"
                required
                autoComplete="off"
              />
            </Field>

            <Field
              label="Password"
              error={fieldError('password')}
              hint={
                isNew
                  ? 'Encrypted before it is stored'
                  : connection!.has_password
                  ? 'Blank keeps the stored password'
                  : 'No password is stored yet'
              }
            >
              <input
                type="password"
                value={values.password}
                onChange={(event) => set('password', event.target.value)}
                className={`${controlClass} w-full`}
                placeholder={isNew ? '' : '••••••••'}
                required={isNew}
                autoComplete="new-password"
              />
            </Field>

            <Field
              label="Timezone"
              error={fieldError('timezone')}
              hint="Offset the server stores dates in"
            >
              <input
                type="text"
                value={values.timezone}
                onChange={(event) => set('timezone', event.target.value)}
                className={`${controlClass} w-full font-mono`}
                placeholder="+08:00"
              />
            </Field>
          </div>

          <label className="flex items-center gap-2 text-sm cursor-pointer">
            <input
              type="checkbox"
              checked={values.enabled}
              onChange={(event) => set('enabled', event.target.checked)}
              className="rounded"
            />
            <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
              Enabled — the portal reads from this database
            </span>
          </label>

          {/* Advanced, and rarely needed: only for several operating companies
              sharing one database rather than each having their own. Hidden by
              default so it cannot be filled in by mistake, which would filter
              every row out and read as an empty database. */}
          <div className={`pt-3 border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-200'}`}>
            <button
              type="button"
              onClick={() => setShowScope((open) => !open)}
              className={`text-xs font-semibold ${isDarkMode ? 'text-blue-300' : 'text-blue-600'}`}
            >
              {showScope ? 'Hide' : 'Show'} row scope (advanced)
            </button>

            {showScope && (
              <div className="mt-3 space-y-3">
                <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                  Only needed when one database holds several operating companies separated by a
                  column. Leave both blank when this database belongs to a single company — which is
                  the usual case.
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <Field label="Scope column" error={fieldError('scope_column')}>
                    <input
                      type="text"
                      value={values.scope_column}
                      onChange={(event) => set('scope_column', event.target.value)}
                      className={`${controlClass} w-full font-mono`}
                      placeholder="organization_id"
                    />
                  </Field>
                  <Field label="Scope value" error={fieldError('scope_value')}>
                    <input
                      type="text"
                      value={values.scope_value}
                      onChange={(event) => set('scope_value', event.target.value)}
                      className={`${controlClass} w-full font-mono`}
                      placeholder="3"
                    />
                  </Field>
                </div>
              </div>
            )}
          </div>

          <div className="flex items-center gap-2 pt-1">
            <Button
              variant="primary"
              type="submit"
              icon={saving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
              disabled={saving}
            >
              {saving ? 'Saving and testing…' : isNew ? 'Save and test' : 'Save changes'}
            </Button>
            <Button variant="outline" onClick={onCancel} disabled={saving}>
              Cancel
            </Button>
          </div>
        </form>
      </CardBody>
    </Card>
  );
};

/** Label, control, hint and error in one consistent block. */
const Field: React.FC<{
  label: string;
  error?: string | null;
  hint?: string;
  children: React.ReactNode;
}> = ({ label, error, hint, children }) => {
  const isDarkMode = useTheme();

  return (
    <label className="block">
      <span className={`block text-xs font-semibold mb-1 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
        {label}
      </span>
      {children}
      {error ? (
        <span className="block text-xs text-red-500 mt-0.5">{error}</span>
      ) : hint ? (
        <span className={`block text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
          {hint}
        </span>
      ) : null}
    </label>
  );
};

export default ConnectionForm;
