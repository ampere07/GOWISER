/**
 * Display formatting shared by the reporting pages.
 *
 * All money is Philippine pesos with two decimals, because these figures are
 * reconciled against printed receipts — the abbreviated form MetricCard uses
 * for executive headlines would not survive that comparison.
 */

const PESO = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

export const formatMoney = (value: number | null | undefined): string =>
  PESO.format(Number(value ?? 0));

/** Compact peso, for chart axes where the full form does not fit. */
export const formatMoneyShort = (value: number | null | undefined): string => {
  const amount = Number(value ?? 0);
  const sign = amount < 0 ? '-' : '';
  const magnitude = Math.abs(amount);

  if (magnitude >= 1_000_000) return `${sign}₱${(magnitude / 1_000_000).toFixed(1)}M`;
  if (magnitude >= 1_000) return `${sign}₱${Math.round(magnitude / 1_000)}k`;

  return `${sign}₱${Math.round(magnitude)}`;
};

export const formatNumber = (value: number | null | undefined): string =>
  Number(value ?? 0).toLocaleString('en-PH');

/** Pluralises a counted noun: 1 payment, 0 payments. */
export const pluralise = (count: number, singular: string, plural?: string): string =>
  `${formatNumber(count)} ${count === 1 ? singular : plural ?? `${singular}s`}`;

/**
 * Parses a value the API produced. Returns null rather than an Invalid Date, so
 * callers render an em dash instead of "NaN".
 *
 * 'YYYY-MM-DD HH:MM:SS' is normalised to ISO first: Safari rejects the space
 * form outright and would show every timestamp as blank.
 */
const parse = (value: string | null | undefined): Date | null => {
  if (!value) return null;

  const date = new Date(value.includes(' ') ? value.replace(' ', 'T') : value);

  return Number.isNaN(date.getTime()) ? null : date;
};

export const formatDate = (value: string | null | undefined): string => {
  const date = parse(value);

  return date
    ? date.toLocaleDateString('en-PH', { month: 'short', day: '2-digit', year: 'numeric' })
    : '—';
};

export const formatDateLong = (value: string | null | undefined): string => {
  const date = parse(value);

  return date
    ? date.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' })
    : '—';
};

export const formatTime = (value: string | null | undefined): string => {
  const date = parse(value);

  return date
    ? date.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true })
    : '—';
};

export const formatDateTime = (value: string | null | undefined): string => {
  const date = parse(value);

  return date ? `${formatDateLong(value)} ${date.toLocaleTimeString('en-PH', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
  })}` : '—';
};

/** "Saturday, August 01 2026" — the dashboard subtitle in the reference. */
export const formatWeekdayDate = (value: string | null | undefined): string => {
  const date = parse(value) ?? new Date();
  const weekday = date.toLocaleDateString('en-PH', { weekday: 'long' });
  const month = date.toLocaleDateString('en-PH', { month: 'long' });
  const day = String(date.getDate()).padStart(2, '0');

  return `${weekday}, ${month} ${day} ${date.getFullYear()}`;
};

export const formatPercent = (value: number | null | undefined, decimals = 1): string =>
  value === null || value === undefined ? '—' : `${Number(value).toFixed(decimals)}%`;

/** Today as YYYY-MM-DD in the browser's timezone, for date inputs. */
export const todayIso = (): string => {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');

  return `${now.getFullYear()}-${month}-${day}`;
};

/** The first day of the current month as YYYY-MM-DD. */
export const monthStartIso = (): string => `${todayIso().slice(0, 7)}-01`;
