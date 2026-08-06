import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertTriangle,
  CalendarDays,
  CalendarRange,
  Check,
  GripVertical,
  Layers,
  Lock,
  Ban,
  Banknote,
  ClipboardList,
  Crown,
  HardHat,
  Lock as LockIcon,
  Maximize2,
  Minimize2,
  Receipt,
  TrendingUp,
  UserMinus,
  UserX,
  Wallet,
  Wifi,
  Wrench,
  Pencil,
  RefreshCw,
  RotateCcw,
  Users,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import { Button, ErrorBanner, Pill, useControlClass } from '../components/reporting/primitives';
import MetricDetailModal, { DetailColumn } from '../components/reporting/MetricDetailModal';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import { useAutoRefresh } from '../hooks/useAutoRefresh';
import { reportingService } from '../services/reportingService';
import {
  ExecutiveMetricKey,
  ExecutiveOverviewData,
  ExecutiveTimeframe,
  MetricRecord,
  SubscriberRecord,
} from '../types/reporting';
import { formatAmount, formatDate, formatNumber } from '../utils/format';

interface ExecutiveOverviewProps {
  refreshToken: number;
}

/** The four blocks, in the order the layout reads them by default. */
type SectionKey = 'range' | 'monthly' | 'subscribers' | 'plans';

const DEFAULT_ORDER: SectionKey[] = ['range', 'monthly', 'subscribers', 'plans'];

const LAYOUT_KEY = 'executive_overview_layout';

const TIMEFRAMES: { key: ExecutiveTimeframe; label: string }[] = [
  { key: 'daily', label: 'Daily' },
  { key: 'weekly', label: 'Weekly' },
  { key: 'monthly', label: 'Monthly' },
  { key: 'yearly', label: 'Yearly' },
];

/** Which drill-down is open, if any. */
type DrillDown =
  | { kind: 'metric'; metric: ExecutiveMetricKey; label: string }
  | { kind: 'status'; status: string; label: string; plan?: string }
  | null;

/**
 * The Executive Group Overview — one daily flash screen for the whole group.
 *
 * Four blocks: the selected range's money, month and year to date, subscriber
 * analytics, and the plan mix. Three things shape everything below.
 *
 * ── Every value field is a bare number ────────────────────────────────
 *
 * This screen exists to be read at a glance, often from across a room, and
 * quoted out loud. A tile whose value reads "₱12,300.00 / 7 days" is a sentence,
 * and a grid of sentences is slower to scan than the module pages it summarises.
 * So the value is a number and nothing else; the currency, the divisor and the
 * window live in the label and caption around it, read once rather than on every
 * glance. Type is deliberately large and high-contrast for the same reason.
 *
 * ── The global date toolbar drives the range ──────────────────────────
 *
 * Daily / Weekly / Monthly / Yearly / Custom sits with the view controls at the
 * top and moves the first block and all five field metrics together. The
 * month-to-date and year-to-date comparatives deliberately do *not* move: a tile
 * labelled "Monthly" showing a yearly figure because somebody pressed a pill is
 * not a smaller error than showing the wrong month, it is a lie about what the
 * label means.
 *
 * ── Every counter opens the rows behind it ────────────────────────────
 *
 * A count is something to worry about; a list is something to act on. Each card
 * backed by records is clickable and opens the same population it counted —
 * built on the same server-side query, so the modal can never disagree with the
 * tile that opened it.
 *
 * Every figure is the same figure the module it came from shows, arrived at by
 * the same code path — see ExecutiveOverviewService for why this view composes
 * section payloads rather than issuing SQL of its own.
 */
const ExecutiveOverview: React.FC<ExecutiveOverviewProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { user } = usePermissions();
  const controlClass = useControlClass();

  const [data, setData] = useState<ExecutiveOverviewData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [reloads, setReloads] = useState(0);

  // ── Global date toolbar ────────────────────────────────────────────
  const [timeframe, setTimeframe] = useState<ExecutiveTimeframe>('daily');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');

  // ── View modes ─────────────────────────────────────────────────────
  const container = useRef<HTMLDivElement>(null);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [editing, setEditing] = useState(false);
  const [order, setOrder] = useState<SectionKey[]>(() => readLayout());
  const [dragging, setDragging] = useState<SectionKey | null>(null);

  const [drill, setDrill] = useState<DrillDown>(null);

  const range = useMemo(
    () =>
      timeframe === 'custom'
        ? { timeframe, dateFrom: customFrom || undefined, dateTo: customTo || undefined }
        : { timeframe },
    [timeframe, customFrom, customTo]
  );

  const load = useCallback(() => {
    let cancelled = false;

    setLoading(true);

    reportingService
      .getExecutiveOverview(range)
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

        setError(err?.response?.data?.message ?? 'Unable to build the executive summary right now.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [range]);

  useEffect(() => load(), [load, refreshToken, reloads]);

  // Paused while a drill-down is open: a table reloading underneath somebody who
  // is reading row forty is worse than a stale one.
  const { seconds, lastRun } = useAutoRefresh(
    'overview_refresh',
    () => setReloads((n) => n + 1),
    drill !== null || editing
  );

  // ── Fullscreen ─────────────────────────────────────────────────────
  const toggleFullscreen = () => {
    if (!container.current) return;

    if (!document.fullscreenElement) {
      container.current.requestFullscreen().catch((err) => {
        setError(`Fullscreen was refused by the browser: ${err.message}`);
      });
    } else {
      document.exitFullscreen();
    }
  };

  useEffect(() => {
    // Driven by the event rather than by the click, so pressing Escape — which
    // exits fullscreen without touching our button — still updates the icon.
    const onChange = () => setIsFullscreen(Boolean(document.fullscreenElement));

    document.addEventListener('fullscreenchange', onChange);
    return () => document.removeEventListener('fullscreenchange', onChange);
  }, []);

  // ── Layout editing ─────────────────────────────────────────────────
  const persist = (next: SectionKey[]) => {
    setOrder(next);
    localStorage.setItem(LAYOUT_KEY, JSON.stringify(next));
  };

  const drop = (target: SectionKey) => {
    if (!dragging || dragging === target) return;

    const next = order.filter((key) => key !== dragging);
    next.splice(next.indexOf(target), 0, dragging);

    persist(next);
    setDragging(null);
  };

  if (forbidden) {
    return (
      <ReportingPage>
        <PageHeader title="Group Overview" />
        <Card>
          <div className="flex items-start gap-3 py-6 px-2">
            <Lock size={20} className={isDarkMode ? 'text-gray-600' : 'text-gray-400'} />
            <div>
              <p className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                Restricted to executive roles
              </p>
              <p className={`text-sm mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                This view requires an executive role.
                {user?.role ? <> Your role ({user.role}) does not have access.</> : null}
              </p>
            </div>
          </div>
        </Card>
      </ReportingPage>
    );
  }

  const first = loading && !data;

  const daily = data?.daily;
  const monthly = data?.monthly;
  const yearly = data?.monthly.yearly;
  const subs = data?.subscribers;
  const plans = data?.plans;
  const selected = data?.windows.selected;

  const money = (value: number | null | undefined): string =>
    first || value === null || value === undefined ? '—' : formatAmount(value);

  const count = (value: number | null | undefined): string =>
    first || value === null || value === undefined ? '—' : formatNumber(value);

  const sections: Record<SectionKey, React.ReactNode> = {
    // ── 1. SELECTED RANGE ───────────────────────────────────────────
    range: (
      <Section
        key="range"
        title={selected?.label ?? 'Daily'}
        subtitle={selected?.label_long}
        icon={<CalendarDays size={18} />}
        note="Amounts in PHP"
      >
        {daily?.masked ? (
          <MaskedBlock />
        ) : !daily?.available && !first ? (
          <Unavailable label="Collections for this period could not be read." />
        ) : (
          <>
            <Row>
              <Tile icon={<Banknote size={20} />} label="Income" value={money(daily?.income)} caption="cash + PNB + Xendit" tone="success" />
              <Tile icon={<Receipt size={20} />} label="Expenses" value={money(daily?.expenses)} caption="OPEX + CAPEX" tone="warning" />
              <Tile
                label="Net"
                icon={<Wallet size={20} />}
                value={money(daily?.net)}
                caption="income − expenses"
                tone={(daily?.net ?? 0) >= 0 ? 'success' : 'danger'}
              />
              <Tile
                label="Monthly Projected Sales"
                icon={<TrendingUp size={20} />}
                value={money(daily?.monthly_projected_sales)}
                caption="month-to-date ÷ days elapsed × days in month"
                tone="info"
              />
            </Row>

            <Row>
              <Tile label="Office Collection" value={money(daily?.office_collection)} caption="over the counter" />
              <Tile label="PNB" value={money(daily?.pnb)} caption="bank collections" />
              <Tile label="Xendit" value={money(daily?.xendit)} caption="payment portal" />
              <Tile
                label="Daily Sales Average"
                value={money(daily?.daily_sales_average)}
                caption="last 7 days ÷ 7"
                tone="info"
              />
            </Row>

            {(daily?.unmatched ?? 0) > 0 && (
              <Footnote tone="warning">
                {formatAmount(daily?.unmatched)} was collected through a payment method matching no
                configured channel, and is therefore outside Income above.
              </Footnote>
            )}
          </>
        )}
      </Section>
    ),

    // ── 2. MONTHLY ──────────────────────────────────────────────────
    monthly: (
      <Section
        key="monthly"
        title="Monthly"
        subtitle={monthly?.range_label}
        icon={<CalendarRange size={18} />}
        note="Amounts in PHP · fixed period"
      >
        {monthly?.masked ? (
          <MaskedBlock />
        ) : !monthly?.available && !first ? (
          <Unavailable label="This month's collections could not be read." />
        ) : (
          <>
            <Row>
              <Tile icon={<Banknote size={20} />} label="Total Income" value={money(monthly?.total_income)} caption="cash + PNB + Xendit" tone="success" />
              <Tile icon={<Receipt size={20} />} label="Total Expenses" value={money(monthly?.total_expenses)} caption="OPEX + CAPEX" tone="warning" />
              <Tile
                label="Net Income"
                icon={<Wallet size={20} />}
                value={money(monthly?.net_income)}
                caption="income − expenses"
                tone={(monthly?.net_income ?? 0) >= 0 ? 'success' : 'danger'}
              />
              <Tile
                label="Weekly Sales Average"
                icon={<TrendingUp size={20} />}
                value={money(monthly?.weekly_sales_average)}
                caption="last 7 days in full"
                tone="info"
              />
            </Row>

            <Row>
              <Tile label="Total Cash" value={money(monthly?.total_cash)} caption="over the counter" />
              <Tile label="Total PNB" value={money(monthly?.total_pnb)} caption="bank collections" />
              <Tile label="Total Xendit" value={money(monthly?.total_xendit)} caption="payment portal" />

              <NestedTile label="Yearly" caption={yearly?.range_label}>
                <NestedFigure label="Income" value={money(yearly?.income)} tone="success" />
                <NestedFigure label="Expenses" value={money(yearly?.expenses)} tone="warning" />
                <NestedFigure
                  label="Net"
                  value={money(yearly?.net)}
                  tone={(yearly?.net ?? 0) >= 0 ? 'success' : 'danger'}
                />
              </NestedTile>
            </Row>
          </>
        )}
      </Section>
    ),

    // ── 3. SUBSCRIBER ANALYTICS ─────────────────────────────────────
    subscribers: (
      <Section
        key="subscribers"
        title="Subscriber Analytics"
        subtitle={
          selected ? `Headcount now · field work ${selected.label_long.toLowerCase()}` : undefined
        }
        icon={<Users size={18} />}
        note="Counts"
      >
        {!subs?.available && !first ? (
          <Unavailable label="The subscriber base could not be read." />
        ) : (
          <>
            <Row>
              <Tile
                label="Active"
                value={count(subs?.active)}
                caption="billing status"
                tone="success"
                icon={<Wifi size={20} />}
                onOpen={() => setDrill({ kind: 'status', status: 'active', label: 'Active' })}
              />
              <Tile
                label="VIP"
                value={count(subs?.vip)}
                caption="connected, not billed"
                tone="info"
                icon={<Crown size={20} />}
                onOpen={() => setDrill({ kind: 'status', status: 'vip', label: 'VIP' })}
              />
              <Tile
                label="Inactive"
                value={count(subs?.inactive)}
                caption="billing status"
                tone="warning"
                icon={<UserMinus size={20} />}
                onOpen={() => setDrill({ kind: 'status', status: 'inactive', label: 'Inactive' })}
              />
              <Tile
                label="Pullout"
                value={count(subs?.pullout)}
                caption="equipment recovered"
                tone="danger"
                icon={<UserX size={20} />}
                onOpen={() => setDrill({ kind: 'status', status: 'pullout', label: 'Pullout' })}
              />
            </Row>

            {/* The five field metrics. Each states the rule behind it in its
                caption, because a bare number under a one-word label is exactly
                where two people start meaning different things. */}
            <Row wide>
              <Tile
                label="Application"
                icon={<ClipboardList size={20} />}
                value={count(subs?.application)}
                caption="filed in range · any status"
                onOpen={() => setDrill({ kind: 'metric', metric: 'application', label: 'Applications' })}
              />
              <Tile
                label="Installed"
                icon={<HardHat size={20} />}
                value={count(subs?.installed)}
                caption="onsite Done · by install date"
                tone="success"
                onOpen={() => setDrill({ kind: 'metric', metric: 'installed', label: 'Installed' })}
              />
              <Tile
                label="Repair"
                icon={<Wrench size={20} />}
                value={count(subs?.repair)}
                caption="visit Done · by modified date"
                tone="success"
                onOpen={() => setDrill({ kind: 'metric', metric: 'repair', label: 'Repairs' })}
              />
              {/* Renamed from "Reschedule" and "Pending". Both were ambiguous
                  on a screen that also counts repairs: "Pending" read as any
                  outstanding work rather than specifically an install visit in
                  progress. The backend filters are unchanged — onsite_status =
                  Reschedule and visit_status = In Progress, both on the modified
                  date — only the labels moved. */}
              <Tile
                label="Rescheduled Install"
                icon={<Ban size={20} />}
                value={count(subs?.reschedule)}
                caption="onsite Reschedule · by modified date"
                tone="warning"
                onOpen={() =>
                  setDrill({ kind: 'metric', metric: 'reschedule', label: 'Rescheduled Install' })
                }
              />
              <Tile
                label="Pending Install"
                icon={<LockIcon size={20} />}
                value={count(subs?.pending)}
                caption="visit In Progress · by modified date"
                tone="warning"
                onOpen={() => setDrill({ kind: 'metric', metric: 'pending', label: 'Pending Install' })}
              />
            </Row>

            {subs?.available && !subs.work_available && !first && (
              <Footnote>
                No monitored system records applications, job orders or service orders, so the row
                above is blank rather than zero — none of that work is tracked here.
              </Footnote>
            )}
          </>
        )}
      </Section>
    ),

    // ── 4. SUBSCRIBER PLAN ──────────────────────────────────────────
    plans: (
      <Section
        key="plans"
        title="Subscriber Plan"
        subtitle={
          plans?.available && plans.rows.length > 0
            ? `${formatNumber(plans.total)} active subscribers across ${formatNumber(
                plans.rows.length
              )} plans`
            : undefined
        }
        icon={<Layers size={18} />}
        note="Counts"
      >
        {!plans?.available && !first ? (
          <Unavailable label="The plan list could not be read." />
        ) : first ? (
          <Row>
            <Tile label="—" value="—" />
            <Tile label="—" value="—" />
            <Tile label="—" value="—" />
            <Tile label="—" value="—" />
          </Row>
        ) : plans && plans.rows.length === 0 ? (
          <Unavailable label="No plan currently carries an active subscriber." />
        ) : (
          <>
            <Row>
              {plans?.rows.map((plan) => (
                <Tile
                  key={plan.label}
                  label={plan.label}
                  value={formatNumber(plan.count)}
                  caption="active subscribers"
                  // Same drill-down as the status counters, narrowed to this
                  // plan. The tile counts active subscribers, so the list is
                  // active subscribers on that plan — the population the number
                  // came from, not every account that ever held it.
                  onOpen={() =>
                    setDrill({
                      kind: 'status',
                      status: 'active',
                      plan: plan.label,
                      label: `${plan.label} · active`,
                    })
                  }
                />
              ))}
            </Row>

            <Footnote>
              Active billing status only. Accounts whose plan link was lost in the migration are
              matched back onto the master plan list by name before counting, so these add up to the
              Active figure above rather than falling short of it.
            </Footnote>
          </>
        )}
      </Section>
    ),
  };

  return (
    <div
      ref={container}
      className={isFullscreen ? (isDarkMode ? 'bg-gray-950 overflow-y-auto h-screen' : 'bg-gray-50 overflow-y-auto h-screen') : ''}
    >
      <ReportingPage>
        <PageHeader
          title="Group Overview"
          subtitle={
            <>
              Daily flash · all databases
              {data && <> · as of {data.as_of}</>}
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
              <Button
                icon={editing ? <Check size={15} /> : <Pencil size={15} />}
                variant={editing ? 'primary' : 'outline'}
                onClick={() => setEditing((current) => !current)}
                title="Drag the sections into the order you read them in"
              >
                {editing ? 'Done' : 'Edit Layout'}
              </Button>

              <Button
                icon={isFullscreen ? <Minimize2 size={15} /> : <Maximize2 size={15} />}
                onClick={toggleFullscreen}
                title={isFullscreen ? 'Exit full screen' : 'Full screen'}
              >
                {isFullscreen ? 'Exit' : 'Full Screen'}
              </Button>

              <Button
                icon={<RefreshCw size={15} className={loading ? 'animate-spin' : ''} />}
                onClick={() => setReloads((n) => n + 1)}
                disabled={loading}
              >
                Refresh
              </Button>
            </div>
          }
        />

        {/* ── Global date toolbar ──────────────────────────────────────
            Sits with the view controls because it governs the whole screen
            rather than any one panel — a per-card range control is how two
            people end up quoting different periods off the same page. */}
        <div
          className={`flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2.5 ${
            isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'
          }`}
        >
          <span
            className={`text-xs font-bold uppercase tracking-wider mr-1 ${
              isDarkMode ? 'text-gray-500' : 'text-gray-400'
            }`}
          >
            Period
          </span>

          <div
            className={`inline-flex rounded-lg p-0.5 ${isDarkMode ? 'bg-gray-950' : 'bg-gray-100'}`}
            role="radiogroup"
            aria-label="Date range"
          >
            {TIMEFRAMES.map((option) => {
              const active = timeframe === option.key;

              return (
                <button
                  key={option.key}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  onClick={() => setTimeframe(option.key)}
                  className={`rounded-md px-3.5 py-1.5 text-sm font-bold transition-colors ${
                    active
                      ? isDarkMode
                        ? 'bg-blue-500/20 text-blue-300'
                        : 'bg-white text-blue-700 shadow-sm'
                      : isDarkMode
                      ? 'text-gray-400 hover:text-gray-200'
                      : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  {option.label}
                </button>
              );
            })}
          </div>

          <button
            type="button"
            role="radio"
            aria-checked={timeframe === 'custom'}
            onClick={() => setTimeframe('custom')}
            className={`rounded-lg border px-3.5 py-1.5 text-sm font-bold transition-colors ${
              timeframe === 'custom'
                ? 'border-blue-500 bg-blue-500/10 text-blue-700 dark:text-blue-300'
                : isDarkMode
                ? 'border-gray-700 text-gray-300 hover:border-gray-600'
                : 'border-gray-300 text-gray-700 hover:border-gray-400'
            }`}
          >
            Custom Range
          </button>

          {timeframe === 'custom' && (
            <span className="flex items-center gap-1.5">
              <input
                type="date"
                value={customFrom}
                max={customTo || undefined}
                onChange={(event) => setCustomFrom(event.target.value)}
                className={`${controlClass} tabular-nums`}
                aria-label="Range start"
              />
              <span className={isDarkMode ? 'text-gray-500' : 'text-gray-400'}>→</span>
              <input
                type="date"
                value={customTo}
                min={customFrom || undefined}
                onChange={(event) => setCustomTo(event.target.value)}
                className={`${controlClass} tabular-nums`}
                aria-label="Range end"
              />
            </span>
          )}

          {selected && (
            <Pill tone="info" className="ml-auto">
              {selected.label_long}
            </Pill>
          )}
        </div>

        {editing && (
          <div
            className={`flex flex-wrap items-center justify-between gap-2 rounded-xl border border-dashed px-3 py-2 text-sm ${
              isDarkMode ? 'border-blue-800 text-blue-200' : 'border-blue-300 text-blue-800'
            }`}
          >
            <span className="flex items-center gap-2">
              <GripVertical size={15} />
              Drag a section by its handle to reorder. Saved to this browser.
            </span>
            <Button icon={<RotateCcw size={13} />} onClick={() => persist(DEFAULT_ORDER)}>
              Reset
            </Button>
          </div>
        )}

        {error && <ErrorBanner message={error} />}

        {data && data.databases.failed.length > 0 && (
          <div
            className={`flex items-start gap-2 rounded-xl border px-3 py-2.5 text-sm ${
              isDarkMode
                ? 'border-amber-800/60 bg-amber-500/10 text-amber-200'
                : 'border-amber-200 bg-amber-50 text-amber-800'
            }`}
          >
            <AlertTriangle size={16} className="mt-0.5 flex-shrink-0" />
            <span>
              <strong>
                {data.databases.answered.length} of {data.databases.total} databases
              </strong>{' '}
              answered. Every figure below is short by whatever the rest hold.
            </span>
          </div>
        )}

        {order.map((key) => (
          <div
            key={key}
            draggable={editing}
            onDragStart={() => setDragging(key)}
            onDragEnd={() => setDragging(null)}
            onDragOver={(event) => editing && event.preventDefault()}
            onDrop={() => drop(key)}
            className={
              editing
                ? `relative rounded-xl transition-opacity ${
                    dragging === key ? 'opacity-40' : ''
                  } ring-2 ring-dashed ${isDarkMode ? 'ring-blue-800' : 'ring-blue-300'}`
                : ''
            }
          >
            {editing && (
              <span
                className={`absolute -top-3 left-4 z-10 flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-bold cursor-grab active:cursor-grabbing ${
                  isDarkMode ? 'bg-blue-500/20 text-blue-200' : 'bg-blue-600 text-white'
                }`}
              >
                <GripVertical size={12} /> drag
              </span>
            )}
            {sections[key]}
          </div>
        ))}

        <p className={`text-xs ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
          Composed from the reporting modules, so every figure here is the one that module shows.
          Generated {data?.generated_at ?? '—'}.
        </p>
      </ReportingPage>

      {/* ── Drill-downs ─────────────────────────────────────────────── */}
      {drill?.kind === 'metric' && (
        <MetricDetailModal<MetricRecord>
          title={drill.label}
          subtitle={selected ? `${selected.label_long} · click a column to sort` : undefined}
          columns={METRIC_COLUMNS}
          filters={[]}
          defaultSort="occurred_at"
          fetchPage={(query) =>
            reportingService.getMetricRecords({
              metric: drill.metric,
              dateFrom: selected?.from,
              dateTo: selected?.to,
              search: query.search,
              plan: query.filters.plan,
              area: query.filters.area,
              sort: query.sort,
              direction: query.direction,
              page: query.page,
            })
          }
          onClose={() => setDrill(null)}
        />
      )}

      {drill?.kind === 'status' && (
        <MetricDetailModal<SubscriberRecord>
          title={`${drill.label} subscribers`}
          subtitle={
            drill.plan
              ? 'Active subscribers on this plan · click a column to sort'
              : 'Current billing status · click a column to sort'
          }
          columns={SUBSCRIBER_COLUMNS}
          defaultSort="subscriber"
          defaultDirection="asc"
          fetchPage={(query) =>
            reportingService.getSubscribersByStatus({
              status: drill.status,
              plan: drill.plan,
              search: query.search,
              page: query.page,
            })
          }
          onClose={() => setDrill(null)}
        />
      )}
    </div>
  );
};

/** The saved section order, falling back to the default on anything unexpected. */
const readLayout = (): SectionKey[] => {
  try {
    const stored = JSON.parse(localStorage.getItem(LAYOUT_KEY) || 'null');

    if (!Array.isArray(stored)) return DEFAULT_ORDER;

    // Reconciled against the current section list rather than trusted: a build
    // that adds or removes a section must not leave a saved layout hiding the
    // new one or rendering a key that no longer exists.
    const kept = stored.filter((key: SectionKey) => DEFAULT_ORDER.includes(key));
    const missing = DEFAULT_ORDER.filter((key) => !kept.includes(key));

    return [...kept, ...missing];
  } catch {
    return DEFAULT_ORDER;
  }
};

// ── Drill-down columns ────────────────────────────────────────────────

/**
 * The first non-empty value among several aliases.
 *
 * The drill-down payloads carry every field under two names — the reporting
 * vocabulary and SYNC's own — because two different systems read this endpoint.
 * Reading through this rather than picking one is what stopped the subscriber
 * modal rendering rows of empty cells with only the status badge filled in: the
 * table was reading `subscriber` against a payload that spelled it
 * `customer_name`, and a missing key is not an error anywhere, it is just blank.
 *
 * Returns an em dash rather than an empty string, so a genuinely absent value
 * looks deliberate instead of looking like a broken column.
 */
const cellText = (row: Record<string, unknown>, ...keys: string[]): string => {
  for (const key of keys) {
    const value = row[key];

    if (value !== null && value !== undefined && String(value).trim() !== '') {
      return String(value);
    }
  }

  return '—';
};

const METRIC_COLUMNS: DetailColumn<MetricRecord>[] = [
  {
    key: 'subscriber',
    header: 'Name',
    cell: (row) => (
      <span className="font-semibold">{cellText(row as any, 'subscriber', 'customer_name')}</span>
    ),
  },
  {
    key: 'account_number',
    header: 'Account No.',
    cell: (row) => (
      <span className="tabular-nums">{cellText(row as any, 'account_number', 'account_no')}</span>
    ),
  },
  {
    key: 'plan',
    header: 'Plan',
    secondary: true,
    cell: (row) => cellText(row as any, 'plan', 'plan_name'),
  },
  {
    key: 'location',
    header: 'Area',
    secondary: true,
    cell: (row) => cellText(row as any, 'location', 'area'),
  },
  {
    key: 'status',
    header: 'Status',
    cell: (row) => {
      const status = cellText(row as any, 'status', 'raw_status');

      return status === '—' ? '—' : <Pill tone="neutral">{status}</Pill>;
    },
  },
  {
    header: 'Technician',
    secondary: true,
    cell: (row) => cellText(row as any, 'technician'),
  },
  {
    key: 'occurred_at',
    header: 'Date',
    align: 'right',
    cell: (row) => <span className="tabular-nums">{formatDate(row.occurred_at) || '—'}</span>,
  },
];

const SUBSCRIBER_COLUMNS: DetailColumn<SubscriberRecord>[] = [
  {
    key: 'subscriber',
    header: 'Name',
    cell: (row) => (
      <span className="font-semibold">{cellText(row as any, 'subscriber', 'customer_name')}</span>
    ),
  },
  {
    key: 'account_number',
    header: 'Account No.',
    cell: (row) => (
      <span className="tabular-nums">{cellText(row as any, 'account_number', 'account_no')}</span>
    ),
  },
  {
    key: 'plan',
    header: 'Plan',
    secondary: true,
    cell: (row) => cellText(row as any, 'plan', 'plan_name'),
  },
  {
    key: 'location',
    header: 'Area',
    secondary: true,
    cell: (row) => cellText(row as any, 'location', 'area'),
  },
  {
    header: 'Contact',
    secondary: true,
    cell: (row) => (
      <span className="tabular-nums">{cellText(row as any, 'contact_number', 'contact')}</span>
    ),
  },
  {
    key: 'status',
    header: 'Status',
    // The source's own word, not the reported label — a reader looking at a
    // Restricted list is entitled to see which of them the system calls
    // Suspended.
    cell: (row) => {
      const status = cellText(row as any, 'raw_status', 'status');

      return status === '—' ? '—' : <Pill tone="neutral">{status}</Pill>;
    },
  },
  {
    header: 'Installed',
    align: 'right',
    secondary: true,
    cell: (row) => <span className="tabular-nums">{formatDate(row.date_installed) || '—'}</span>,
  },
];

// ── Layout ────────────────────────────────────────────────────────────

/**
 * One of the four blocks.
 *
 * A plain card with a hairline header — the same surface every other module in
 * MONITOR uses. The layout reference fills these blocks with a flat colour; that
 * marks out the grid structure and is not a design instruction, and painting
 * four saturated panels onto a portal built on neutral cards would read as a
 * different application bolted on.
 */
const Section: React.FC<{
  title: string;
  subtitle?: string;
  icon: React.ReactNode;
  note?: string;
  children: React.ReactNode;
}> = ({ title, subtitle, icon, note, children }) => {
  const isDarkMode = useTheme();

  return (
    <section
      className={`rounded-2xl border ${
        isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200'
      }`}
    >
      {/* Header set as the reference does it: the title in wide-tracked
          uppercase carrying the weight, the scope pushed to the right rail on
          one line. No rule under it — the gap does the separating, which keeps a
          stack of these reading as one page rather than as four boxed reports. */}
      <div className="flex flex-wrap items-baseline justify-between gap-2 px-6 pt-6 pb-4">
        <h2
          className={`flex items-center gap-2.5 text-lg font-bold uppercase tracking-[0.12em] ${
            isDarkMode ? 'text-white' : 'text-gray-900'
          }`}
        >
          <span className="text-blue-500">{icon}</span>
          {title}
        </h2>

        <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          {subtitle}
          {subtitle && note ? ' · ' : ''}
          {note}
        </span>
      </div>

      <div className="px-6 pb-6 space-y-4">{children}</div>
    </section>
  );
};

/**
 * One row of the grid.
 *
 * Four across on a desktop, five where a row genuinely holds five. Four is the
 * reference layout's own width and about the most figures that stay legible side
 * by side at this type size; the fifth column is only used where the alternative
 * is orphaning one metric onto a row of its own.
 */
const Row: React.FC<{ children: React.ReactNode; wide?: boolean }> = ({ children, wide }) => (
  <div
    className={`grid grid-cols-1 sm:grid-cols-2 gap-4 ${
      wide ? 'xl:grid-cols-5' : 'xl:grid-cols-4'
    }`}
  >
    {children}
  </div>
);

type Tone = 'success' | 'danger' | 'warning' | 'neutral' | 'info';

const TONE_TEXT: Record<Tone, string> = {
  success: 'text-emerald-700 dark:text-emerald-300',
  danger: 'text-red-700 dark:text-red-300',
  warning: 'text-amber-700 dark:text-amber-300',
  info: 'text-blue-700 dark:text-blue-300',
  neutral: '',
};

/** Icon colour only — the number stays near-black unless it carries meaning. */
const TONE_ICON: Record<Tone, string> = {
  success: 'text-emerald-500',
  danger: 'text-red-500',
  warning: 'text-amber-500',
  info: 'text-blue-500',
  neutral: 'text-gray-400',
};

/**
 * One figure.
 *
 * The value is a bare number — no currency mark, no unit, no window. Tabular
 * numerals so a column of them aligns on the decimal point and can be compared
 * down the page without reading each one, which is how a flash screen is
 * actually used.
 *
 * ── Contrast and size ─────────────────────────────────────────────────
 *
 * Deliberately large and heavy: this is read at a distance and often by someone
 * who is not wearing their reading glasses. The tone colours are the 700/300
 * pair rather than 600/400 so every one of them clears WCAG AA against both
 * surfaces — a tinted numeral that fails contrast is worse than a plain one,
 * because it looks deliberate.
 *
 * The tone shows only in the numeral and a rule down the left edge. Filling the
 * tile with colour would put four saturated blocks in a row and turn a scannable
 * grid into a traffic light.
 *
 * A tile with `onOpen` is a button, not a div with a click handler: it has to be
 * reachable by keyboard and announce itself, because it is the only route to the
 * records behind the number.
 */
const Tile: React.FC<{
  label: string;
  value: React.ReactNode;
  caption?: string;
  tone?: Tone;
  icon?: React.ReactNode;
  onOpen?: () => void;
}> = ({ label, value, caption, tone = 'neutral', icon, onOpen }) => {
  const isDarkMode = useTheme();

  const body = (
    <>
      {/* Label left, icon right, on one baseline — the reference layout. The
          icon is the only colour in the tile until the number needs one. */}
      <div className="flex items-start justify-between gap-2">
        <p
          className={`text-xs font-semibold uppercase tracking-[0.1em] truncate ${
            isDarkMode ? 'text-gray-400' : 'text-gray-500'
          }`}
          title={label}
        >
          {label}
        </p>

        {icon && <span className={`flex-shrink-0 ${TONE_ICON[tone]}`}>{icon}</span>}
      </div>

      {/* The number is the tile. Deliberately the largest thing on the page and
          set in tabular figures so a column of them aligns on the decimal point
          — this screen is read down, at a distance, without reading each one. */}
      <p
        className={`mt-2 text-4xl font-bold tabular-nums tracking-tight truncate ${
          TONE_TEXT[tone] || (isDarkMode ? 'text-white' : 'text-gray-900')
        }`}
      >
        {value}
      </p>

      {caption && (
        <p
          className={`mt-1.5 text-[11px] truncate ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}
          title={caption}
        >
          {caption}
        </p>
      )}
    </>
  );

  // Flat surface and a hairline border, matching the reference. The tinted fill
  // and coloured left rule this replaced put four saturated blocks in a row and
  // turned a scannable grid into a traffic light.
  const shell = `rounded-xl border px-5 py-4 text-left w-full transition-all ${
    isDarkMode ? 'bg-gray-950/40 border-gray-800' : 'bg-white border-gray-200'
  }`;

  if (!onOpen) {
    return <div className={shell}>{body}</div>;
  }

  return (
    <button
      type="button"
      onClick={onOpen}
      title={`Open the records behind ${label}`}
      className={`${shell} cursor-pointer focus:outline-none focus:ring-2 focus:ring-blue-500/60 ${
        isDarkMode
          ? 'hover:border-gray-600 hover:bg-gray-900'
          : 'hover:border-gray-300 hover:shadow-md'
      }`}
    >
      {body}
    </button>
  );
};

/** A tile holding several figures — the Yearly trio in the Monthly block. */
const NestedTile: React.FC<{
  label: string;
  caption?: string;
  children: React.ReactNode;
}> = ({ label, caption, children }) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`rounded-xl border px-5 py-4 ${
        isDarkMode ? 'bg-gray-950/40 border-gray-800' : 'bg-white border-gray-200'
      }`}
    >
      <p
        className={`text-xs font-semibold uppercase tracking-[0.1em] ${
          isDarkMode ? 'text-gray-400' : 'text-gray-500'
        }`}
      >
        {label}
      </p>

      <div className="mt-1 space-y-0.5">{children}</div>

      {caption && (
        <p className={`mt-1 text-[11px] ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
          {caption}
        </p>
      )}
    </div>
  );
};

const NestedFigure: React.FC<{ label: string; value: React.ReactNode; tone?: Tone }> = ({
  label,
  value,
  tone = 'neutral',
}) => {
  const isDarkMode = useTheme();

  return (
    <div className="flex items-baseline justify-between gap-2">
      <span className={`text-xs font-medium ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
        {label}
      </span>
      <span
        className={`text-lg font-bold tabular-nums truncate ${
          TONE_TEXT[tone] || (isDarkMode ? 'text-white' : 'text-gray-900')
        }`}
      >
        {value}
      </span>
    </div>
  );
};

// ── States ────────────────────────────────────────────────────────────

/**
 * A block the role may see the shape of but not the figures in.
 *
 * Distinct from "unavailable" on purpose: one sends the reader to an
 * administrator, the other to whoever owns the database.
 */
const MaskedBlock: React.FC = () => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`flex items-center gap-2.5 rounded-xl border border-dashed px-4 py-6 text-sm ${
        isDarkMode ? 'border-gray-800 text-gray-400' : 'border-gray-300 text-gray-500'
      }`}
    >
      <Lock size={16} className="flex-shrink-0" />
      Financial figures are restricted for your role.
    </div>
  );
};

const Unavailable: React.FC<{ label: string }> = ({ label }) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`flex items-center gap-2.5 rounded-xl border border-dashed px-4 py-6 text-sm ${
        isDarkMode ? 'border-gray-800 text-gray-400' : 'border-gray-300 text-gray-500'
      }`}
    >
      <AlertTriangle size={16} className="flex-shrink-0" />
      {label}
    </div>
  );
};

const Footnote: React.FC<{ tone?: 'neutral' | 'warning'; children: React.ReactNode }> = ({
  tone = 'neutral',
  children,
}) => {
  const isDarkMode = useTheme();

  const colour =
    tone === 'warning'
      ? isDarkMode
        ? 'text-amber-300'
        : 'text-amber-700'
      : isDarkMode
      ? 'text-gray-400'
      : 'text-gray-500';

  return <p className={`text-[11px] leading-relaxed ${colour}`}>{children}</p>;
};

export default ExecutiveOverview;
