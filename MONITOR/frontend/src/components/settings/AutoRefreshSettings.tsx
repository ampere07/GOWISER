import React, { useEffect, useState } from 'react';
import { Check, Loader2, RefreshCw } from 'lucide-react';
import Card, { CardHeader, CardBody } from '../reporting/Card';
import { Button, ErrorBanner } from '../reporting/primitives';
import { usePermissions } from '../../hooks/usePermissions';
import { useTheme } from '../../hooks/useTheme';
import { updatePreferences } from '../../services/api';
import { REFRESH_CHOICES, UserPreferences } from '../../types/api';

const DEFAULTS: UserPreferences = { overview_refresh: 0, mikrotik_refresh: 60 };

/**
 * How often the auto-refreshing dashboards re-read themselves.
 *
 * ── Why this is a per-user setting and not a portal-wide one ──────────
 *
 * The two readers of these screens want opposite things. A NOC operator with the
 * Group Overview on a wall display wants thirty seconds; an executive who opens
 * it twice a day wants it off, because a page that reloads under them while they
 * are reading a figure is worse than a stale one. A single portal-wide interval
 * would force one of them to live with the other's choice.
 *
 * ── Why it is not free-form ───────────────────────────────────────────
 *
 * The intervals are a fixed list, on both sides. This number decides how often
 * the portal fans out across every monitored database, and on the MikroTik
 * screen how often it reaches routers that are simultaneously serving live
 * authentication. A free-form field would let somebody type 2 and quietly turn
 * the dashboard into a load generator.
 *
 * Saved against the account rather than the browser, so it follows the person to
 * whichever machine they sign in on — and so the load it implies is visible in
 * one place rather than hidden in a dozen browsers.
 */
const AutoRefreshSettings: React.FC = () => {
  const isDarkMode = useTheme();
  const { user } = usePermissions();

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
        subtitle="How often your dashboards re-read themselves"
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
            Saved. Open dashboards pick this up immediately.
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
          <Interval
            id="overview-refresh"
            label="Group Overview"
            value={draft.overview_refresh}
            onChange={(value) => setDraft({ ...draft, overview_refresh: value })}
            hint="Each refresh fans out across every monitored database. Off is a sensible default for a screen read a few times a day."
          />

          <Interval
            id="mikrotik-refresh"
            label="MikroTik RADIUS"
            value={draft.mikrotik_refresh}
            onChange={(value) => setDraft({ ...draft, mikrotik_refresh: value })}
            hint="Live sessions go stale within a minute. Each refresh reaches routers that are also serving authentication, so shorter is not always better."
          />
        </div>

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

        <p className={`mt-4 text-xs leading-relaxed ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          Saved against your account, not this browser, so it follows you to whichever machine you
          sign in on. Refreshing pauses while the tab is in the background and runs once as soon as
          you come back to it — a dashboard left open overnight costs nothing and is current the
          moment you look at it.
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
  onChange: (value: number) => void;
}> = ({ id, label, value, hint, onChange }) => {
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
              onClick={() => onChange(choice.value)}
              className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors ${
                active
                  ? 'border-blue-500 bg-blue-500/10 text-blue-700 dark:text-blue-300'
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
