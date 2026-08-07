import React, { useEffect, useState } from 'react';
import { Check, Loader2, Lock, RefreshCw } from 'lucide-react';
import Card, { CardHeader, CardBody } from '../reporting/Card';
import { Button, ErrorBanner } from '../reporting/primitives';
import { usePermissions } from '../../hooks/usePermissions';
import { useTheme } from '../../hooks/useTheme';
import { updatePreferences } from '../../services/api';
import { REFRESH_CHOICES, UserPreferences } from '../../types/api';
import { ACTION } from '../../types/rbac';

const DEFAULTS: UserPreferences = { overview_refresh: 0, mikrotik_refresh: 60 };

/**
 * How often the auto-refreshing dashboards re-read themselves.
 *
 * ── Why this is portal-wide and no longer per-user ────────────────────
 *
 * It used to be saved against the account, on the argument that the two readers
 * of these screens want opposite things: a NOC operator with the Group Overview
 * on a wall display wants thirty seconds, an executive who opens it twice a day
 * wants it off. That argument treats the interval as a display preference. It is
 * not one — it decides how often the portal fans out across every monitored
 * database and, on the MikroTik screen, how often it reaches routers that are
 * simultaneously serving live authentication. Held per user, the load on
 * production was the sum of a dozen private choices that appeared in no single
 * place, and nobody could answer "how hard are we hitting SYNC" without
 * enumerating accounts.
 *
 * One value, set once, visible here. It follows from that that changing it
 * needs `action.settings.manage`, as the logo and the palette do: this now
 * changes the portal for everyone rather than for the person changing it.
 *
 * ── Why it is not free-form ───────────────────────────────────────────
 *
 * The intervals are a fixed list, on both sides. A free-form field would let
 * somebody type 2 and quietly turn every open dashboard into a load generator.
 */
const AutoRefreshSettings: React.FC = () => {
  const isDarkMode = useTheme();
  const { user, can } = usePermissions();

  // Read-only without the grant. Shown rather than hidden: knowing what the
  // portal is set to is useful to anybody, and a panel that vanishes reads as a
  // missing feature rather than as a restriction.
  const editable = can(ACTION.settingsManage);

  const stored: UserPreferences = {
    overview_refresh: user?.preferences?.overview_refresh ?? DEFAULTS.overview_refresh,
    mikrotik_refresh: user?.preferences?.mikrotik_refresh ?? DEFAULTS.mikrotik_refresh,
  };

  const [draft, setDraft] = useState<UserPreferences>(stored);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  // Re-seeded when the stored value changes — after a save, and after another
  // tab changed it. Keyed on the values rather than on the user object so an
  // unrelated re-render does not discard a half-made choice.
  useEffect(() => {
    setDraft({
      overview_refresh: stored.overview_refresh,
      mikrotik_refresh: stored.mikrotik_refresh,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stored.overview_refresh, stored.mikrotik_refresh]);

  const dirty =
    draft.overview_refresh !== stored.overview_refresh ||
    draft.mikrotik_refresh !== stored.mikrotik_refresh;

  const save = async () => {
    setSaving(true);
    setError(null);
    setSaved(false);

    try {
      await updatePreferences(draft);
      setSaved(true);
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'That interval could not be saved.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card flush>
      <CardHeader
        title="Auto-Refresh"
        subtitle="How often the dashboards re-read themselves — for every account"
        icon={<RefreshCw size={16} />}
      />
      <CardBody>
        {error && (
          <div className="mb-4">
            <ErrorBanner message={error} />
          </div>
        )}

        {saved && !dirty && (
          <div className="mb-4 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center gap-3 text-sm">
            <Check size={16} className="flex-shrink-0" />
            Saved. Every open dashboard, on every account, picks this up immediately.
          </div>
        )}

        {!editable && (
          <div
            className={`mb-4 flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
              isDarkMode
                ? 'border-gray-700 bg-gray-800/40 text-gray-400'
                : 'border-gray-200 bg-gray-50 text-gray-600'
            }`}
          >
            <Lock size={15} className="mt-0.5 flex-shrink-0" />
            <span>
              These intervals apply to every account, so changing them needs the settings
              permission. This is what the portal is currently set to.
            </span>
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
          <Interval
            id="overview-refresh"
            label="Dashboards"
            value={draft.overview_refresh}
            onChange={(value) => setDraft({ ...draft, overview_refresh: value })}
            disabled={!editable}
            hint="Every reporting page, not only the Group Overview — this is the poll the whole dashboard runs on. Each cycle fans out across every monitored database, for every viewer at once, so Off is a sensible default for screens read a few times a day."
          />

          <Interval
            id="mikrotik-refresh"
            label="MikroTik RADIUS"
            value={draft.mikrotik_refresh}
            onChange={(value) => setDraft({ ...draft, mikrotik_refresh: value })}
            disabled={!editable}
            hint="Live sessions go stale within a minute. Each refresh reaches routers that are also serving authentication, so shorter is not always better."
          />
        </div>

        {editable && (
          <div className="mt-4 flex items-center gap-2">
            <Button
              variant="primary"
              onClick={save}
              disabled={saving || !dirty}
              icon={saving ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
            >
              Save
            </Button>

            {dirty && (
              <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                Unsaved changes
              </span>
            )}
          </div>
        )}

        <p className={`mt-4 text-xs leading-relaxed ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          One setting for the whole portal, not per account and not per browser — this decides how
          often MONITOR reads production, so it is a single visible number rather than a dozen
          private ones. <strong>Off</strong> means no polling at all: pages then load once and
          refresh when somebody presses Refresh. Otherwise refreshing pauses while a tab is in the
          background, and while a drill-down or dialog is open in front of somebody, and runs once as
          soon as they come back — so a dashboard left open overnight costs nothing and is current
          the moment it is looked at.
        </p>
      </CardBody>
    </Card>
  );
};

const Interval: React.FC<{
  id: string;
  label: string;
  value: number;
  hint: string;
  disabled?: boolean;
  onChange: (value: number) => void;
}> = ({ id, label, value, hint, disabled = false, onChange }) => {
  const isDarkMode = useTheme();

  return (
    <div>
      <span
        className={`block text-xs font-semibold mb-2 ${
          isDarkMode ? 'text-gray-300' : 'text-gray-600'
        }`}
      >
        {label}
      </span>

      {/* A segmented control rather than a <select>: there are five options, the
          whole point is comparing them, and a dropdown hides four of the five
          behind a click. */}
      <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-labelledby={id}>
        {REFRESH_CHOICES.map((choice) => {
          const active = value === choice.value;

          return (
            <button
              key={choice.value}
              type="button"
              role="radio"
              aria-checked={active}
              disabled={disabled}
              onClick={() => onChange(choice.value)}
              className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors ${
                disabled ? 'cursor-not-allowed' : ''
              } ${
                active
                  ? 'border-blue-500 bg-blue-500/10 text-blue-700 dark:text-blue-300'
                  : disabled
                  ? isDarkMode
                    ? 'border-gray-800 text-gray-600'
                    : 'border-gray-200 text-gray-400'
                  : isDarkMode
                  ? 'border-gray-700 text-gray-300 hover:border-gray-600 hover:bg-gray-800'
                  : 'border-gray-300 text-gray-700 hover:border-gray-400 hover:bg-gray-50'
              }`}
            >
              {choice.label}
            </button>
          );
        })}
      </div>

      <p className={`text-[11px] mt-2 leading-relaxed ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
        {hint}
      </p>
    </div>
  );
};

export default AutoRefreshSettings;
