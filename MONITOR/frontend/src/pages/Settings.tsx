import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  AlertTriangle,
  Check,
  CheckCircle2,
  Coins,
  Image as ImageIcon,
  Loader2,
  Palette,
  Pencil,
  Plus,
  Trash2,
  Upload,
  X,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import { Button, ErrorBanner, Pill, useControlClass } from '../components/reporting/primitives';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import {
  BrandingSettings,
  ColorPalette,
  PaletteFormValues,
  settingsColorPaletteService,
} from '../services/settingsColorPaletteService';
import { ACTION } from '../types/rbac';
import bundledLogo from '../assets/gowiserlogo.png';

interface SettingsProps {
  refreshToken: number;
}

/** Two megabytes, matching the server's rule — checked here to fail fast. */
const MAX_LOGO_BYTES = 2 * 1024 * 1024;

const ACCEPTED = 'image/png,image/jpeg,image/webp';

const emptyPalette: PaletteFormValues = {
  palette_name: '',
  primary: '#7c3aed',
  secondary: '#6d28d9',
  accent: '#a78bfa',
};

/**
 * Settings — the portal's logo and colour palette.
 *
 * Both are branding, both are singletons, and both change how the app looks for
 * everyone rather than for the person changing them. That is why the writes sit
 * behind `action.settings.manage` while reading is open to any session: the
 * sidebar and the login screen render these values on every load.
 *
 * A role without the grant still gets the page, read-only. Hiding it entirely
 * would leave someone unable to see which palette is live — which is a
 * legitimate question that changing it is not.
 */
const Settings: React.FC<SettingsProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { can } = usePermissions();
  const controlClass = useControlClass();

  const fileInput = useRef<HTMLInputElement>(null);

  const [branding, setBranding] = useState<BrandingSettings | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const [editing, setEditing] = useState<ColorPalette | null>(null);
  const [form, setForm] = useState<PaletteFormValues | null>(null);

  // Held as the raw input string rather than a number so a half-typed value is
  // not coerced — "1." parses to 1 and would fight the person typing "1.50".
  const [syncPriceDraft, setSyncPriceDraft] = useState('0');
  const [hostingFeeDraft, setHostingFeeDraft] = useState('0');

  const editable = can(ACTION.settingsManage);

  // Two decimals both sides, so the dirty check compares like with like: the
  // server stores "150.00" and someone typing "150" has not changed anything.
  const storedSyncPrice = (branding?.sync_price?.rate ?? 0).toFixed(2);
  const storedHostingFee = (branding?.hosting_fee?.rate ?? 0).toFixed(2);

  const load = useCallback(() => {
    setLoading(true);

    settingsColorPaletteService
      .getBranding()
      .then((result) => {
        setBranding(result);
        setError(null);
      })
      .catch((err) => setError(err?.response?.data?.message ?? 'Unable to load settings.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => load(), [load, refreshToken]);

  // Re-seeded whenever the stored rate changes — after a save, and after a poll
  // that picked up someone else's. Keyed on the stored value rather than on
  // `branding`, so a reload that changed only the palettes does not discard a
  // rate somebody is halfway through typing.
  useEffect(() => setSyncPriceDraft(storedSyncPrice), [storedSyncPrice]);
  useEffect(() => setHostingFeeDraft(storedHostingFee), [storedHostingFee]);

  /** Reports the outcome, then reloads so the screen shows what was stored. */
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

  const saveSyncPrice = () => {
    const rate = Number(syncPriceDraft);

    // Rejected here as well as on the server. The server's message would be
    // correct but generic, and this one can name the field the person is
    // looking at.
    if (!Number.isFinite(rate) || rate < 0 || rate > 100000) {
      setError('Enter a rate between 0 and 100,000.');
      return;
    }

    run(
      'sync-price',
      () => settingsColorPaletteService.updateSyncPrice(rate),
      'SYNC price per customer updated. The Executive Dashboard will use it on its next load.'
    );
  };

  const saveHostingFee = () => {
    const rate = Number(hostingFeeDraft);

    if (!Number.isFinite(rate) || rate < 0 || rate > 10000000) {
      setError('Enter a rate between 0 and 10,000,000.');
      return;
    }

    run(
      'hosting-fee',
      () => settingsColorPaletteService.updateHostingFee(rate),
      'Hosting fee updated. The Executive Dashboard will use it on its next load.'
    );
  };

  const pickLogo = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];

    // Reset immediately so choosing the same file twice still fires a change
    // event — otherwise a failed upload cannot be retried without picking a
    // different file first.
    event.target.value = '';

    if (!file) return;

    if (file.size > MAX_LOGO_BYTES) {
      setError('That image is over 2 MB. Please use a smaller file.');
      return;
    }

    run('logo', () => settingsColorPaletteService.uploadLogo(file), 'Logo updated.');
  };

  const removeLogo = () => {
    if (!window.confirm('Remove the system logo? The bundled mark will be used instead.')) {
      return;
    }

    run('logo', () => settingsColorPaletteService.deleteLogo(), 'Logo removed.');
  };

  const savePalette = () => {
    if (!form) return;

    run(
      'palette',
      async () => {
        if (editing) {
          await settingsColorPaletteService.updatePalette(editing.id, form);
        } else {
          await settingsColorPaletteService.createPalette(form);
        }

        setForm(null);
        setEditing(null);
      },
      editing ? 'Palette updated.' : 'Palette created.'
    );
  };

  const palettes = branding?.palettes ?? [];

  return (
    <ReportingPage>
      <PageHeader
        title="Settings"
        subtitle={
          editable
            ? 'Branding, and the SYNC price the Executive Dashboard charges against'
            : 'Read-only — your role cannot change these settings'
        }
      />

      {error && <ErrorBanner message={error} />}

      {notice && (
        <div className="p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center gap-3 text-sm">
          <CheckCircle2 size={16} className="flex-shrink-0" />
          {notice}
        </div>
      )}

      {/* ── SYNC price per customer ───────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="SYNC Price per Customer"
          subtitle="The platform fee charged per billable subscriber, per month"
          icon={<Coins size={16} />}
        />
        <CardBody>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div>
              <label
                htmlFor="sync-price"
                className={`block text-xs font-semibold mb-1.5 ${
                  isDarkMode ? 'text-gray-300' : 'text-gray-600'
                }`}
              >
                Rate per subscriber (₱)
              </label>

              <div className="flex items-center gap-2">
                <input
                  id="sync-price"
                  type="number"
                  min={0}
                  max={100000}
                  step="0.01"
                  inputMode="decimal"
                  value={syncPriceDraft}
                  disabled={!editable || loading}
                  onChange={(event) => setSyncPriceDraft(event.target.value)}
                  onKeyDown={(event) => event.key === 'Enter' && editable && saveSyncPrice()}
                  className={`${controlClass} w-40 tabular-nums`}
                  aria-describedby="sync-price-help"
                />

                {editable && (
                  <Button
                    variant="primary"
                    icon={
                      busy === 'sync-price' ? (
                        <Loader2 size={14} className="animate-spin" />
                      ) : (
                        <Check size={14} />
                      )
                    }
                    onClick={saveSyncPrice}
                    title="Save the SYNC price per customer"
                    // Disabled until the value differs from what is stored, so
                    // the button is never a no-op that still writes an audit row.
                    disabled={busy !== null || syncPriceDraft === storedSyncPrice}
                  >
                    Save
                  </Button>
                )}
              </div>

              <p
                id="sync-price-help"
                className={`text-[11px] mt-2 leading-relaxed ${
                  isDarkMode ? 'text-gray-500' : 'text-gray-500'
                }`}
              >
                SYNC is licensed per subscriber, so its cost is a headcount times this rate rather
                than a line in any expenses ledger — which is why it is set here and not imported.
                Leave it at 0 and the Executive Dashboard reports it as unconfigured rather than as
                a ₱0.00 expense.
              </p>
            </div>

            <div
              className={`rounded-lg border p-4 ${
                isDarkMode ? 'border-gray-800 bg-gray-950' : 'border-gray-200 bg-gray-50'
              }`}
            >
              <p className={`text-xs font-semibold mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-600'}`}>
                Excluded from the headcount
              </p>

              <div className="flex flex-wrap gap-1.5 mb-3">
                {(branding?.sync_price?.excluded_statuses ?? []).map((status) => (
                  <Pill key={status} tone="neutral">
                    {status.replace(/\b\w/g, (letter) => letter.toUpperCase())}
                  </Pill>
                ))}
              </div>

              <p className={`text-[11px] leading-relaxed ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                Fixed rather than editable. The exclusion is applied in the same query that takes the
                count, so the headcount and the money can never be computed over different
                populations — which is the failure a second setting here would invite.
              </p>
            </div>
          </div>
        </CardBody>
      </Card>

      {/* ── Hosting fee ──────────────────────────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="Hosting Fee"
          subtitle="Flat monthly infrastructure charge"
          icon={<Coins size={16} />}
        />
        <CardBody>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div>
              <label
                htmlFor="hosting-fee"
                className={`block text-xs font-semibold mb-1.5 ${
                  isDarkMode ? 'text-gray-300' : 'text-gray-600'
                }`}
              >
                Monthly fee (₱)
              </label>

              <div className="flex items-center gap-2">
                <input
                  id="hosting-fee"
                  type="number"
                  min={0}
                  max={10000000}
                  step="0.01"
                  inputMode="decimal"
                  value={hostingFeeDraft}
                  disabled={!editable || loading}
                  onChange={(event) => setHostingFeeDraft(event.target.value)}
                  onKeyDown={(event) => event.key === 'Enter' && editable && saveHostingFee()}
                  className={`${controlClass} w-40 tabular-nums`}
                  aria-describedby="hosting-fee-help"
                />

                {editable && (
                  <Button
                    variant="primary"
                    icon={
                      busy === 'hosting-fee' ? (
                        <Loader2 size={14} className="animate-spin" />
                      ) : (
                        <Check size={14} />
                      )
                    }
                    onClick={saveHostingFee}
                    title="Save the hosting fee"
                    disabled={busy !== null || hostingFeeDraft === storedHostingFee}
                  >
                    Save
                  </Button>
                )}
              </div>

              <p
                id="hosting-fee-help"
                className={`text-[11px] mt-2 leading-relaxed ${
                  isDarkMode ? 'text-gray-500' : 'text-gray-500'
                }`}
              >
                A flat monthly charge for infrastructure — not per subscriber. Unlike the SYNC
                platform fee, this is a single fixed amount regardless of the subscriber count.
                Leave it at 0 and the Executive Dashboard reports it as unconfigured.
              </p>
            </div>
          </div>
        </CardBody>
      </Card>

      {/* ── System logo ───────────────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="System Logo"
          subtitle="Shown in the sidebar, the header and on the login screen"
          icon={<ImageIcon size={16} />}
        />
        <CardBody>
          <div
            className={`rounded-lg border flex items-center justify-center p-6 ${
              isDarkMode ? 'border-gray-800 bg-gray-950' : 'border-gray-200 bg-white'
            }`}
            style={{ minHeight: 180 }}
          >
            {loading && !branding ? (
              <Loader2 size={20} className="animate-spin text-gray-400" />
            ) : (
              <img
                // Falls back to the bundled mark rather than an empty box, so
                // "no logo uploaded" still looks like a working portal.
                src={branding?.logo ?? bundledLogo}
                alt="System logo"
                className="max-h-32 max-w-full object-contain"
              />
            )}
          </div>

          {!branding?.logo && !loading && (
            <p className={`mt-2 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
              No logo uploaded — the bundled mark is being used.
            </p>
          )}

          {editable && (
            <div className="mt-4 flex flex-wrap gap-2">
              <input
                ref={fileInput}
                type="file"
                accept={ACCEPTED}
                onChange={pickLogo}
                className="hidden"
              />

              <Button
                variant="primary"
                icon={
                  busy === 'logo' ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <Upload size={14} />
                  )
                }
                onClick={() => fileInput.current?.click()}
                disabled={busy === 'logo'}
              >
                {branding?.logo ? 'Change Logo' : 'Upload Logo'}
              </Button>

              {branding?.logo && (
                <Button
                  variant="danger"
                  icon={<Trash2 size={14} />}
                  onClick={removeLogo}
                  disabled={busy === 'logo'}
                >
                  Delete Logo
                </Button>
              )}
            </div>
          )}

          <p className={`mt-3 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            PNG, JPG or WebP, up to 2 MB. A transparent PNG reads correctly in both light and dark
            themes. SVG is deliberately not accepted — it can carry script, and this file is served
            to every visitor including on the login screen before anyone has signed in.
          </p>
        </CardBody>
      </Card>

      {/* ── Colour palette ────────────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="Colour Palette"
          subtitle="Exactly one palette is active at a time"
          icon={<Palette size={16} />}
        />
        <CardBody>
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            {palettes.map((palette) => (
              <PaletteCard
                key={palette.id}
                palette={palette}
                editable={editable}
                busy={busy === `palette-${palette.id}`}
                onActivate={() =>
                  run(
                    `palette-${palette.id}`,
                    () => settingsColorPaletteService.activatePalette(palette.id),
                    `"${palette.palette_name}" is now active.`
                  )
                }
                onEdit={() => {
                  setEditing(palette);
                  setForm({
                    palette_name: palette.palette_name,
                    primary: palette.primary,
                    secondary: palette.secondary,
                    accent: palette.accent,
                  });
                }}
                onDelete={() => {
                  if (!window.confirm(`Delete the palette "${palette.palette_name}"?`)) return;

                  run(
                    `palette-${palette.id}`,
                    () => settingsColorPaletteService.deletePalette(palette.id),
                    'Palette deleted.'
                  );
                }}
              />
            ))}

            {editable && (
              <button
                type="button"
                onClick={() => {
                  setEditing(null);
                  setForm(emptyPalette);
                }}
                className={`rounded-xl border border-dashed flex flex-col items-center justify-center gap-2 p-6 transition-colors ${
                  isDarkMode
                    ? 'border-gray-700 hover:border-gray-600 hover:bg-gray-900'
                    : 'border-gray-300 hover:border-gray-400 hover:bg-gray-50'
                }`}
                style={{ minHeight: 160 }}
              >
                <span
                  className={`w-9 h-9 rounded-full flex items-center justify-center ${
                    isDarkMode ? 'bg-gray-800 text-gray-400' : 'bg-gray-100 text-gray-500'
                  }`}
                >
                  <Plus size={18} />
                </span>
                <span className={`text-sm font-medium ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                  Add Custom Palette
                </span>
                <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                  Create your own colour scheme
                </span>
              </button>
            )}
          </div>

          {palettes.length === 0 && !loading && !editable && (
            <p className={`text-sm ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
              No palettes are configured.
            </p>
          )}

          <p className={`mt-4 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            A new palette is created inactive — adding one should not repaint the portal underneath
            you. Activating is a separate click, and the active palette cannot be deleted until
            another has taken over.
          </p>
        </CardBody>
      </Card>

      {/* ── Palette editor ────────────────────────────────────────────── */}
      {form && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
          <div
            className={`w-full max-w-md rounded-xl border ${
              isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'
            }`}
          >
            <div
              className={`flex items-center justify-between gap-3 px-5 py-3.5 border-b ${
                isDarkMode ? 'border-gray-800' : 'border-gray-200'
              }`}
            >
              <h3 className={`font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {editing ? `Edit ${editing.palette_name}` : 'Add custom palette'}
              </h3>
              <button
                type="button"
                onClick={() => {
                  setForm(null);
                  setEditing(null);
                }}
                className={
                  isDarkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-black'
                }
              >
                <X size={18} />
              </button>
            </div>

            <CardBody>
              <label className="block mb-3">
                <span className={`block text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                  Palette name
                </span>
                <input
                  className={`${controlClass} w-full`}
                  value={form.palette_name}
                  onChange={(event) => setForm({ ...form, palette_name: event.target.value })}
                  placeholder="e.g. GO WISER"
                />
              </label>

              {(
                [
                  ['primary', 'Primary', 'Active navigation, primary buttons'],
                  ['secondary', 'Secondary', 'Hover and pressed states'],
                  ['accent', 'Accent', 'Highlights and badges'],
                ] as const
              ).map(([key, label, hint]) => (
                <label key={key} className="flex items-center gap-3 mb-3">
                  {/* A colour well beside a hex field: the picker is how someone
                      chooses, the text is how they paste a brand value they were
                      given. Both write the same state. */}
                  <input
                    type="color"
                    value={form[key]}
                    onChange={(event) => setForm({ ...form, [key]: event.target.value })}
                    className="w-12 h-10 rounded-lg border-0 bg-transparent cursor-pointer flex-shrink-0"
                    aria-label={label}
                  />
                  <span className="min-w-0 flex-1">
                    <span
                      className={`block text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}
                    >
                      {label}
                    </span>
                    <input
                      className={`${controlClass} w-full font-mono !py-1 !text-xs mt-0.5`}
                      value={form[key]}
                      onChange={(event) => setForm({ ...form, [key]: event.target.value })}
                      placeholder="#000000"
                    />
                    <span
                      className={`block text-[11px] mt-0.5 ${
                        isDarkMode ? 'text-gray-600' : 'text-gray-400'
                      }`}
                    >
                      {hint}
                    </span>
                  </span>
                </label>
              ))}

              <div className="mt-3 p-2.5 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-600 dark:text-amber-400 flex items-start gap-2 text-xs">
                <AlertTriangle size={14} className="mt-0.5 flex-shrink-0" />
                Six-digit hex only (#rrggbb). Named colours are rejected — the sidebar appends an
                alpha suffix to the primary when it tints the active item, and that only works on
                hex.
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
                onClick={savePalette}
                disabled={busy === 'palette'}
                icon={
                  busy === 'palette' ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <Check size={14} />
                  )
                }
              >
                Save
              </Button>
            </div>
          </div>
        </div>
      )}
    </ReportingPage>
  );
};

/** One palette: its swatches, and what can be done with it. */
const PaletteCard: React.FC<{
  palette: ColorPalette;
  editable: boolean;
  busy: boolean;
  onActivate: () => void;
  onEdit: () => void;
  onDelete: () => void;
}> = ({ palette, editable, busy, onActivate, onEdit, onDelete }) => {
  const isDarkMode = useTheme();
  const active = palette.status === 'active';

  return (
    <div
      className={`rounded-xl border p-4 transition-colors ${
        active
          ? 'border-emerald-500'
          : isDarkMode
          ? 'border-gray-800 bg-gray-900'
          : 'border-gray-200 bg-white'
      }`}
    >
      <div className="flex items-start justify-between gap-2 mb-3">
        <span className="min-w-0">
          <p className={`font-semibold truncate ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
            {palette.palette_name}
          </p>
          {active && (
            <Pill tone="success" className="mt-1">
              Active
            </Pill>
          )}
        </span>

        {editable && (
          <span className="flex items-center gap-1 flex-shrink-0">
            {busy ? (
              <Loader2 size={14} className="animate-spin text-gray-400" />
            ) : (
              <>
                {!active && (
                  <button
                    type="button"
                    onClick={onActivate}
                    title="Make this the active palette"
                    className="rounded-full border border-emerald-500/40 text-emerald-600 dark:text-emerald-400 p-1 hover:bg-emerald-500/10 transition-colors"
                  >
                    <Check size={13} />
                  </button>
                )}
                <button
                  type="button"
                  onClick={onEdit}
                  title="Edit colours"
                  className={`rounded-full border p-1 transition-colors ${
                    isDarkMode
                      ? 'border-gray-700 text-gray-400 hover:bg-gray-800'
                      : 'border-gray-300 text-gray-500 hover:bg-gray-50'
                  }`}
                >
                  <Pencil size={13} />
                </button>
                <button
                  type="button"
                  onClick={onDelete}
                  // The active palette cannot be deleted — the backend refuses
                  // it, and a disabled control says so before the click.
                  disabled={active}
                  title={
                    active ? 'Activate another palette before deleting this one' : 'Delete palette'
                  }
                  className="rounded-full border border-red-500/40 text-red-500 p-1 hover:bg-red-500/10 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                >
                  <Trash2 size={13} />
                </button>
              </>
            )}
          </span>
        )}
      </div>

      <div className="grid grid-cols-3 gap-2">
        {(
          [
            ['primary', 'Primary'],
            ['secondary', 'Secondary'],
            ['accent', 'Accent'],
          ] as const
        ).map(([key, label]) => (
          <span key={key}>
            <span
              className="block h-11 rounded-lg border border-black/5"
              style={{ backgroundColor: palette[key] }}
            />
            <span
              className={`block text-[11px] text-center mt-1 ${
                isDarkMode ? 'text-gray-500' : 'text-gray-500'
              }`}
            >
              {label}
            </span>
            <span
              className={`block text-[10px] text-center font-mono ${
                isDarkMode ? 'text-gray-600' : 'text-gray-400'
              }`}
            >
              {palette[key]}
            </span>
          </span>
        ))}
      </div>
    </div>
  );
};

export default Settings;
