import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertTriangle,
  CalendarClock,
  CalendarDays,
  CalendarRange,
  Check,
  Columns2,
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
  Rows2,
  SlidersHorizontal,
  Sun,
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
import Card from '../components/reporting/Card';
import { Button, ErrorBanner, Pill, useControlClass } from '../components/reporting/primitives';
import MetricDetailModal, { DetailColumn } from '../components/reporting/MetricDetailModal';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import { useSuspendRefresh } from '../hooks/useAutoRefresh';
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

/**
 * How wide a block is, in columns of the two-column page grid.
 *
 * Two is the full page width and the default for every block. One puts two
 * blocks side by side, which is what a wall display with room to spare wants and
 * what a laptop does not — so it is opt-in per block rather than a breakpoint.
 */
type SectionSpan = 1 | 2;

const DEFAULT_SPANS: Record<SectionKey, SectionSpan> = {
  range: 2,
  monthly: 2,
  subscribers: 2,
  plans: 2,
};

interface Layout {
  order: SectionKey[];
  spans: Record<SectionKey, SectionSpan>;
}

const DEFAULT_LAYOUT: Layout = { order: DEFAULT_ORDER, spans: DEFAULT_SPANS };

/**
 * Version suffix on the storage key.
 *
 * The saved value used to be a bare array of section keys and is now an object
 * carrying widths as well. Reading the old shape through the new parser would
 * fall back to the default anyway, but a fresh key means a browser that has been
 * through both builds cannot end up with half of each.
 */
const LAYOUT_KEY = 'executive_overview_layout_v2';

/**
 * Tells the tiles inside a block how much room they have.
 *
 * A block set to half width still contains rows written as "four across", and
 * four tiles in half a page is four unreadable columns. Passed by context rather
 * than as a prop because the rows are declared several levels down inside a
 * section's own JSX, and threading a width through every one of them would mean
 * remembering to do so on each new row.
 */
const SectionWidth = React.createContext<SectionSpan>(2);

/**
 * The four presets, each with the icon it compresses to.
 *
 * The icons are for the sticky bar, where the labels are dropped to buy back
 * vertical room — so they have to be distinguishable at a glance rather than
 * merely thematic: a single day, a week's span, a month grid, a year.
 */
const TIMEFRAMES: { key: ExecutiveTimeframe; label: string; icon: React.ElementType }[] = [
  { key: 'daily', label: 'Daily', icon: Sun },
  { key: 'weekly', label: 'Weekly', icon: CalendarRange },
  { key: 'monthly', label: 'Monthly', icon: CalendarDays },
  { key: 'yearly', label: 'Yearly', icon: CalendarClock },
];

/**
 * Which drill-down is open, if any.
 *
 * A metric drill carries its own window rather than reading the toolbar. The
 * Monthly block is a fixed comparative and deliberately does not move with the
 * period control, so a modal opened from it that used the selected range would
 * list a different population from the tile that opened it — which is the one
 * thing a drill-down must never do.
 */
type DrillDown =
  | {
      kind: 'metric';
      metric: ExecutiveMetricKey;
      label: string;
      /** Omitted for the all-time state metrics — see ALL_TIME_METRICS. */
      window?: { from?: string; to?: string };
    }
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
  const [layout, setLayout] = useState<Layout>(() => readLayout());
  const [dragging, setDragging] = useState<SectionKey | null>(null);

  const { order, spans } = layout;

  // ── Sticky period bar ──────────────────────────────────────────────
  // A sentinel above the bar rather than a scroll listener: IntersectionObserver
  // fires only when the boundary is actually crossed, where a scroll handler
  // runs on every frame of every scroll to answer the same yes/no question.
  const sentinel = useRef<HTMLDivElement>(null);
  const [stuck, setStuck] = useState(false);

  useEffect(() => {
    const element = sentinel.current;

    if (!element || typeof IntersectionObserver === 'undefined') return;

    const observer = new IntersectionObserver(
      ([entry]) => setStuck(!entry.isIntersecting),
      // Nothing clever: the sentinel sits immediately above the bar, so it
      // leaves the viewport at exactly the moment the bar reaches the top.
      { threshold: 0 }
    );

    observer.observe(element);

    return () => observer.disconnect();
  }, []);

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

  /**
   * Held while a drill-down or the layout editor is open.
   *
   * This page used to own a second timer of its own, on the same
   * `overview_refresh` interval that Dashboard's poll now runs on — so once both
   * were configured from the same setting they fired together and every cycle
   * cost two full fan-outs across every monitored database for one visible
   * update. The poll above is the single timer now; this only asks it to wait.
   *
   * A table reloading underneath somebody who is reading row forty is worse than
   * a stale one, and a saved layout being reset mid-drag is worse still.
   */
  useSuspendRefresh(drill !== null || editing);

  const seconds = Number(user?.preferences?.overview_refresh ?? 0);

  // Stamped from the refresh that actually happened rather than from a timer of
  // our own, so the "updated" line cannot claim a reload the page did not do —
  // the manual button and the poll both arrive here, and both are real.
  const [lastRun, setLastRun] = useState<Date | null>(null);

  useEffect(() => {
    if (refreshToken > 0 || reloads > 0) {
      setLastRun(new Date());
    }
  }, [refreshToken, reloads]);

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
  const persist = (next: Layout) => {
    setLayout(next);
    localStorage.setItem(LAYOUT_KEY, JSON.stringify(next));
  };

  const drop = (target: SectionKey) => {
    if (!dragging || dragging === target) return;

    const next = order.filter((key) => key !== dragging);
    next.splice(next.indexOf(target), 0, dragging);

    persist({ ...layout, order: next });
    setDragging(null);
  };

  const resize = (key: SectionKey, span: SectionSpan) =>
    persist({ ...layout, spans: { ...spans, [key]: span } });

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

  // The two windows the money tiles drill into. `selected` follows the period
  // toolbar; `monthly` is the fixed month-to-date comparative and deliberately
  // does not. Each tile passes the one it was computed over, so the modal lists
  // the rows that made the number rather than a different month's.
  const selectedWindow = { from: selected?.from, to: selected?.to };
  const monthlyWindow = { from: data?.windows.monthly.from, to: data?.windows.monthly.to };

  /** The selected period's short name, for a modal title. */
  const rangeLabel = selected?.label ?? 'Selected';

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
            {/* Income and Expenses are totals of rows that exist — payments and
                expense records — so they open the list they added up. Net and
                the two projections below are arithmetic *over* those totals:
                there is no such thing as a "net income record", and a modal
                behind one would have to invent a population to show. Those are
                declared with `formula`, which states the arithmetic and drops
                the click target and the pointer cursor with it. */}
            <Row>
              <Tile icon={<Banknote size={20} />} label="Income" value={money(daily?.income)} caption="cash + PNB + Xendit" tone="success" onOpen={() => setDrill({ kind: 'metric', metric: 'income', label: `${rangeLabel} Income`, window: selectedWindow })} />
              <Tile icon={<Receipt size={20} />} label="Expenses" value={money(daily?.expenses)} caption="OPEX + CAPEX" tone="warning" onOpen={() => setDrill({ kind: 'metric', metric: 'expenses', label: `${rangeLabel} Expenses`, window: selectedWindow })} />
              <Tile
                label="Net"
                icon={<Wallet size={20} />}
                value={money(daily?.net)}
                caption="income − expenses"
                tone={(daily?.net ?? 0) >= 0 ? 'success' : 'danger'}
                formula="Income − Expenses"
              />
              <Tile
                label="Monthly Projected Sales"
                icon={<TrendingUp size={20} />}
                value={money(daily?.monthly_projected_sales)}
                caption="month-to-date ÷ days elapsed × days in month"
                tone="info"
                formula="(Month-to-date income ÷ days elapsed) × days in month"
              />
            </Row>

            <Row>
              <Tile label="Office Collection" value={money(daily?.office_collection)} caption="over the counter" onOpen={() => setDrill({ kind: 'metric', metric: 'office', label: `${rangeLabel} Office Collections`, window: selectedWindow })} />
              <Tile label="PNB" value={money(daily?.pnb)} caption="bank collections" onOpen={() => setDrill({ kind: 'metric', metric: 'pnb', label: `${rangeLabel} PNB Collections`, window: selectedWindow })} />
              <Tile label="Xendit" value={money(daily?.xendit)} caption="payment portal" onOpen={() => setDrill({ kind: 'metric', metric: 'portal', label: `${rangeLabel} Portal Payments`, window: selectedWindow })} />
              <Tile
                label="Daily Sales Average"
                value={money(daily?.daily_sales_average)}
                caption="last 7 days ÷ 7"
                tone="info"
                formula="Last 7 days' income ÷ 7"
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
              <Tile icon={<Banknote size={20} />} label="Total Income" value={money(monthly?.total_income)} caption="cash + PNB + Xendit" tone="success" onOpen={() => setDrill({ kind: 'metric', metric: 'income', label: 'Monthly Total Income', window: monthlyWindow })} />
              <Tile icon={<Receipt size={20} />} label="Total Expenses" value={money(monthly?.total_expenses)} caption="OPEX + CAPEX" tone="warning" onOpen={() => setDrill({ kind: 'metric', metric: 'expenses', label: 'Monthly Total Expenses', window: monthlyWindow })} />
              <Tile
                label="Net Income"
                icon={<Wallet size={20} />}
                value={money(monthly?.net_income)}
                caption="income − expenses"
                tone={(monthly?.net_income ?? 0) >= 0 ? 'success' : 'danger'}
                formula="Total Income − Total Expenses"
              />
              <Tile
                label="Weekly Sales Average"
                icon={<TrendingUp size={20} />}
                value={money(monthly?.weekly_sales_average)}
                caption="last 7 days in full"
                tone="info"
                formula="Last 7 days' income ÷ 7"
              />
            </Row>

            <Row>
              <Tile label="Total Cash" value={money(monthly?.total_cash)} caption="over the counter" onOpen={() => setDrill({ kind: 'metric', metric: 'office', label: 'Monthly Total Cash', window: monthlyWindow })} />
              <Tile label="Total PNB" value={money(monthly?.total_pnb)} caption="bank collections" onOpen={() => setDrill({ kind: 'metric', metric: 'pnb', label: 'Monthly Total PNB', window: monthlyWindow })} />
              <Tile label="Total Xendit" value={money(monthly?.total_xendit)} caption="payment portal" onOpen={() => setDrill({ kind: 'metric', metric: 'portal', label: 'Monthly Total Xendit', window: monthlyWindow })} />

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
                onOpen={() =>
                  setDrill({
                    kind: 'metric',
                    metric: 'application',
                    label: 'Applications',
                    window: selectedWindow,
                  })
                }
              />
              <Tile
                label="Installed"
                icon={<HardHat size={20} />}
                value={count(subs?.installed)}
                caption="onsite Done · by install date"
                tone="success"
                onOpen={() =>
                  setDrill({
                    kind: 'metric',
                    metric: 'installed',
                    label: 'Installed',
                    window: selectedWindow,
                  })
                }
              />
              <Tile
                label="Repair"
                icon={<Wrench size={20} />}
                value={count(subs?.repair)}
                caption="visit Done · by modified date"
                tone="success"
                onOpen={() =>
                  setDrill({
                    kind: 'metric',
                    metric: 'repair',
                    label: 'Repairs',
                    window: selectedWindow,
                  })
                }
              />
              {/* These two are counted all-time, unlike the three beside them,
                  and their captions say so because they sit under the same
                  period control as everything else on the page.

                  Both name a state a job is *in* rather than something that
                  happened on a day. Windowed to the selected range they answered
                  "how many were rescheduled today", which on Daily is usually a
                  handful and hides every install that has been stuck since
                  March — the exact rows somebody opens this tile to find.

                  Both now read job_orders, so they are the two halves of one
                  question — of the installations not yet done, which were put
                  off and which are part way through — and they can be compared
                  and added. Pending Install counted half-finished *repairs*
                  until this build, which is a different queue entirely from the
                  one its label named. */}
              <Tile
                label="Rescheduled Install"
                icon={<Ban size={20} />}
                value={count(subs?.reschedule)}
                caption="onsite Reschedule · overall"
                tone="warning"
                onOpen={() =>
                  setDrill({ kind: 'metric', metric: 'reschedule', label: 'Rescheduled Install' })
                }
              />
              <Tile
                label="Pending Install"
                icon={<LockIcon size={20} />}
                value={count(subs?.pending)}
                caption="onsite In Progress · overall"
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

        {/* Zero-height marker immediately above the bar. Its leaving the
            viewport is what "the bar has reached the top" means, measured
            rather than inferred from a scroll offset that would have to know
            the header's height. */}
        <div ref={sentinel} aria-hidden className="h-px -mb-px" />

        {/* ── Global date toolbar ──────────────────────────────────────
            Sits with the view controls because it governs the whole screen
            rather than any one panel — a per-card range control is how two
            people end up quoting different periods off the same page.

            Sticky, because it governs everything below it: on a screen four
            blocks deep, scrolling to the plan mix used to mean scrolling back up
            to find out which period you were looking at, and the commonest way
            to misread this page is to read a figure without its window.

            Compressed to icons once it sticks. Pinned at full height the bar
            costs a fifth of a laptop viewport permanently — on a screen whose
            whole argument is that the figures are large and read at a distance,
            that is the wrong thing to spend the room on. The labels return the
            moment it unsticks, and each icon keeps its name as a tooltip and as
            its accessible label, so nothing is only a picture. */}
        <div
          className={`sticky top-0 z-30 flex flex-wrap items-center gap-2 rounded-xl border transition-all duration-200 ${
            stuck ? 'px-2 py-1.5 shadow-md backdrop-blur' : 'px-3 py-2.5'
          } ${
            isDarkMode
              ? `border-gray-800 ${stuck ? 'bg-gray-900/95' : 'bg-gray-900'}`
              : `border-gray-200 ${stuck ? 'bg-white/95' : 'bg-white'}`
          }`}
        >
          <span
            className={`text-xs font-bold uppercase tracking-wider mr-1 ${
              stuck ? 'sr-only' : ''
            } ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}
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
              const Icon = option.icon;

              return (
                <button
                  key={option.key}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  aria-label={option.label}
                  title={option.label}
                  onClick={() => setTimeframe(option.key)}
                  className={`flex items-center gap-1.5 rounded-md text-sm font-bold transition-all ${
                    stuck ? 'px-2 py-1' : 'px-3.5 py-1.5'
                  } ${
                    active
                      ? isDarkMode
                        ? 'bg-blue-500/20 text-blue-300'
                        : 'bg-white text-blue-700 shadow-sm'
                      : isDarkMode
                      ? 'text-gray-400 hover:text-gray-200'
                      : 'text-gray-600 hover:text-gray-900'
                  }`}
                >
                  <Icon size={15} className={stuck ? '' : 'hidden'} />
                  {/* Hidden rather than unmounted: the label stays in the
                      accessibility tree at every width, so the control does not
                      become an unnamed button when the bar compresses. */}
                  <span className={stuck ? 'sr-only' : ''}>{option.label}</span>
                </button>
              );
            })}
          </div>

          <button
            type="button"
            role="radio"
            aria-checked={timeframe === 'custom'}
            aria-label="Custom Range"
            title="Custom Range"
            onClick={() => setTimeframe('custom')}
            className={`flex items-center gap-1.5 rounded-lg border text-sm font-bold transition-all ${
              stuck ? 'px-2 py-1' : 'px-3.5 py-1.5'
            } ${
              timeframe === 'custom'
                ? 'border-blue-500 bg-blue-500/10 text-blue-700 dark:text-blue-300'
                : isDarkMode
                ? 'border-gray-700 text-gray-300 hover:border-gray-600'
                : 'border-gray-300 text-gray-700 hover:border-gray-400'
            }`}
          >
            <SlidersHorizontal size={15} className={stuck ? '' : 'hidden'} />
            <span className={stuck ? 'sr-only' : ''}>Custom Range</span>
          </button>

          {/* The date inputs survive compression: they are the only controls
              here that hold a value rather than a choice, and hiding them would
              take a custom range off the screen the moment somebody scrolled. */}
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

          {/* The window itself is the one thing that must never compress away —
              a pinned bar that no longer says which period is worse than no bar,
              because it looks authoritative. */}
          {selected && (
            <Pill tone="info" className="ml-auto">
              {stuck ? selected.label : selected.label_long}
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
              Drag a section by its handle to reorder, and set its width with the
              buttons beside it. Saved to this browser.
            </span>
            <Button icon={<RotateCcw size={13} />} onClick={() => persist(DEFAULT_LAYOUT)}>
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

        {/* Two columns from `xl` up, one below it. A block set to half width is
            still full width on anything narrower than a desktop — a preference
            about how to use spare room cannot be honoured on a screen that has
            none, and forcing it would make the phone layout unreadable to
            satisfy a setting made on a wall display. */}
        <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 items-start">
          {order.map((key) => {
            const span = spans[key];

            return (
              <div
                key={key}
                draggable={editing}
                onDragStart={() => setDragging(key)}
                onDragEnd={() => setDragging(null)}
                onDragOver={(event) => editing && event.preventDefault()}
                onDrop={() => drop(key)}
                className={`${span === 2 ? 'xl:col-span-2' : 'xl:col-span-1'} ${
                  editing
                    ? `relative rounded-xl transition-opacity ${
                        dragging === key ? 'opacity-40' : ''
                      } ring-2 ring-dashed ${isDarkMode ? 'ring-blue-800' : 'ring-blue-300'}`
                    : ''
                }`}
              >
                {editing && (
                  <div className="absolute -top-3 left-4 right-4 z-10 flex items-center justify-between gap-2">
                    <span
                      className={`flex items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-bold cursor-grab active:cursor-grabbing ${
                        isDarkMode ? 'bg-blue-500/20 text-blue-200' : 'bg-blue-600 text-white'
                      }`}
                    >
                      <GripVertical size={12} /> drag
                    </span>

                    {/* Two widths rather than a drag handle on the edge. The
                        page is a two-column grid, so "half" and "full" are the
                        only widths that produce a layout rather than a ragged
                        one — and a resize handle that can only stop at two
                        places is a worse control than two buttons. */}
                    <span
                      className={`flex items-center gap-0.5 rounded-md p-0.5 ${
                        isDarkMode ? 'bg-blue-500/20' : 'bg-blue-600'
                      }`}
                      role="radiogroup"
                      aria-label="Section width"
                    >
                      <WidthButton
                        active={span === 1}
                        label="Half width"
                        icon={<Columns2 size={12} />}
                        onClick={() => resize(key, 1)}
                      />
                      <WidthButton
                        active={span === 2}
                        label="Full width"
                        icon={<Rows2 size={12} />}
                        onClick={() => resize(key, 2)}
                      />
                    </span>
                  </div>
                )}

                <SectionWidth.Provider value={span}>{sections[key]}</SectionWidth.Provider>
              </div>
            );
          })}
        </div>

        <p className={`text-xs ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
          Composed from the reporting modules, so every figure here is the one that module shows.
          Generated {data?.generated_at ?? '—'}.
        </p>
      </ReportingPage>

      {/* ── Drill-downs ─────────────────────────────────────────────── */}
      {drill?.kind === 'metric' && (
        <MetricDetailModal<MetricRecord>
          title={drill.label}
          subtitle={
            ALL_TIME_METRICS.includes(drill.metric)
              ? 'Everything currently in this state · click a column to sort'
              : 'Click a column to sort'
          }
          columns={metricColumns(drill.metric)}
          filters={[]}
          defaultSort="occurred_at"
          fetchPage={(query) =>
            reportingService.getMetricRecords({
              metric: drill.metric,
              // The window the tile was computed over, carried on the drill
              // rather than read from the toolbar — the Monthly block does not
              // follow the toolbar, and a modal that did would list a different
              // month from the tile that opened it.
              //
              // Rescheduled and Pending carry no window at all: the backend
              // counts them all-time, and sending one would misdescribe the
              // request even though it is ignored.
              dateFrom: ALL_TIME_METRICS.includes(drill.metric) ? undefined : drill.window?.from,
              dateTo: ALL_TIME_METRICS.includes(drill.metric) ? undefined : drill.window?.to,
              search: query.search,
              plan: query.filters.plan,
              area: query.filters.area,
              sort: query.sort,
              direction: query.direction,
              page: query.page,
              perPage: query.perPage,
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
              perPage: query.perPage,
            })
          }
          onClose={() => setDrill(null)}
        />
      )}
    </div>
  );
};

/**
 * The saved layout, falling back to the default on anything unexpected.
 *
 * Every field is reconciled against the current section list rather than
 * trusted. A build that adds or removes a section must not leave a saved layout
 * hiding the new one or rendering a key that no longer exists — and a hand-
 * edited localStorage entry is a thing that happens.
 */
const readLayout = (): Layout => {
  try {
    const stored = JSON.parse(localStorage.getItem(LAYOUT_KEY) || 'null');

    if (!stored || typeof stored !== 'object' || !Array.isArray(stored.order)) {
      return DEFAULT_LAYOUT;
    }

    const kept: SectionKey[] = stored.order.filter((key: SectionKey) =>
      DEFAULT_ORDER.includes(key)
    );
    const missing = DEFAULT_ORDER.filter((key) => !kept.includes(key));

    const spans = { ...DEFAULT_SPANS };

    DEFAULT_ORDER.forEach((key) => {
      // Anything that is not literally 1 falls back to full width. A block
      // rendered at some third width the grid has no column count for is worse
      // than one that ignored a saved preference.
      if (stored.spans?.[key] === 1) {
        spans[key] = 1;
      }
    });

    return { order: [...kept, ...missing], spans };
  } catch {
    return DEFAULT_LAYOUT;
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

/**
 * Metrics the backend counts all-time rather than over the selected window.
 *
 * Mirrors the `all_time` flag on GowiserReportsDriver::WORK_METRICS. Duplicated
 * across the wire because the modal has to say which it is showing, and a
 * subtitle claiming "1–7 August" over an all-time list is a worse error than no
 * subtitle: it is a specific false claim about the population on screen.
 */
const ALL_TIME_METRICS: ExecutiveMetricKey[] = ['reschedule', 'pending'];

// ── Shared columns ────────────────────────────────────────────────────

const NAME_COLUMN: DetailColumn<MetricRecord> = {
  key: 'subscriber',
  header: 'Name',
  cell: (row) => (
    <span className="font-semibold">{cellText(row as any, 'subscriber', 'customer_name')}</span>
  ),
};

const ACCOUNT_COLUMN: DetailColumn<MetricRecord> = {
  key: 'account_number',
  header: 'Account No.',
  cell: (row) => (
    <span className="tabular-nums">{cellText(row as any, 'account_number', 'account_no')}</span>
  ),
};

const AREA_COLUMN: DetailColumn<MetricRecord> = {
  key: 'location',
  header: 'Area',
  secondary: true,
  cell: (row) => cellText(row as any, 'location', 'area'),
};

const STATUS_COLUMN: DetailColumn<MetricRecord> = {
  key: 'status',
  header: 'Status',
  cell: (row) => {
    const status = cellText(row as any, 'status', 'raw_status');

    return status === '—' ? '—' : <Pill tone="neutral">{status}</Pill>;
  },
};

const DATE_COLUMN: DetailColumn<MetricRecord> = {
  key: 'occurred_at',
  header: 'Date',
  align: 'right',
  cell: (row) => <span className="tabular-nums">{formatDate(row.occurred_at) || '—'}</span>,
};

/**
 * The peso figure on a money drill-down.
 *
 * Right-aligned and in tabular figures, so a column of them aligns on the
 * decimal point and can be added up by eye against the tile that opened the
 * modal — which is the single thing anybody does with this table.
 */
const AMOUNT_COLUMN: DetailColumn<MetricRecord> = {
  header: 'Amount',
  align: 'right',
  cell: (row) => (
    <span className="tabular-nums font-semibold">
      {row.amount === null || row.amount === undefined ? '—' : formatAmount(row.amount)}
    </span>
  ),
};

/** When the row last changed state — distinct from the metric's own date. */
const MODIFIED_COLUMN: DetailColumn<MetricRecord> = {
  header: 'Modified',
  align: 'right',
  cell: (row) => (
    <span className="tabular-nums">{formatDate((row as any).modified_date) || '—'}</span>
  ),
};

/** The engineer's note from the visit. Truncated, with the whole of it on hover. */
const REMARKS_COLUMN: DetailColumn<MetricRecord> = {
  header: 'Remarks',
  secondary: true,
  cell: (row) => {
    const text = cellText(row as any, 'remarks', 'onsite_remarks', 'visit_remarks');

    return (
      <span className="block max-w-[280px] truncate" title={text === '—' ? undefined : text}>
        {text}
      </span>
    );
  },
};

/**
 * The columns each metric's drill-down carries.
 *
 * Per metric rather than one shared list, because the shared list was mostly
 * blank on three of the five. An application has no billed plan and no
 * technician — it has the plan the applicant asked for and the agent who
 * referred them — and rendering the columns anyway produced a table of em
 * dashes that read as broken rather than as inapplicable.
 *
 * Two further decisions worth stating:
 *
 *  - Rescheduled and Pending drop the account number. Both are installations
 *    that have not completed, so a large share of them have no billing account
 *    yet; the column was empty precisely on the rows somebody opened the modal
 *    to chase. They gain Remarks and Modified instead, which is what actually
 *    tells you why a job is stuck and how long it has been. Identical column
 *    sets because they are now the two halves of one queue — see the tiles.
 *  - A repair is classified by what was wrong, not by what the subscriber pays,
 *    so Plan gives way to Repair Category.
 */
const metricColumns = (metric: ExecutiveMetricKey): DetailColumn<MetricRecord>[] => {
  switch (metric) {
    // ── Money ────────────────────────────────────────────────────────
    // A ledger, not a subscriber list: the amount is the point and it is the
    // only right-aligned figure, so a column of them can be scanned and added
    // up against the tile that opened it.
    case 'expenses':
      return [
        {
          key: 'subscriber',
          header: 'Payee',
          cell: (row) => (
            <span className="font-semibold">{cellText(row as any, 'subscriber')}</span>
          ),
        },
        {
          key: 'plan',
          header: 'Category',
          cell: (row) => cellText(row as any, 'plan', 'plan_name'),
        },
        {
          key: 'status',
          header: 'Booked as',
          secondary: true,
          cell: (row) => {
            const period = cellText(row as any, 'method');

            return period === '—' ? '—' : <Pill tone="neutral">{period}</Pill>;
          },
        },
        REMARKS_COLUMN,
        { header: 'Recorded by', secondary: true, cell: (row) => cellText(row as any, 'technician') },
        AMOUNT_COLUMN,
        DATE_COLUMN,
      ];

    case 'income':
    case 'office':
    case 'pnb':
    case 'portal':
      return [
        NAME_COLUMN,
        ACCOUNT_COLUMN,
        {
          key: 'plan',
          header: 'Type',
          secondary: true,
          cell: (row) => cellText(row as any, 'plan', 'plan_name'),
        },
        {
          key: 'status',
          header: 'Method',
          cell: (row) => {
            const method = cellText(row as any, 'method', 'status');

            return method === '—' ? '—' : <Pill tone="neutral">{method}</Pill>;
          },
        },
        {
          // OR number on a counter payment, gateway reference on a portal one.
          // Named for what it is rather than for either, because the same
          // column carries both and neither name is true of the other.
          header: 'Reference',
          secondary: true,
          cell: (row) => (
            <span className="tabular-nums">{cellText(row as any, 'reference')}</span>
          ),
        },
        { header: 'Cashier', secondary: true, cell: (row) => cellText(row as any, 'technician') },
        AMOUNT_COLUMN,
        DATE_COLUMN,
      ];

    case 'application':
      return [
        NAME_COLUMN,
        ACCOUNT_COLUMN,
        {
          header: 'Desired Plan',
          secondary: true,
          // The applicant's own words. `plan` is the *billed* plan, which an
          // application does not have — that column was blank on every row.
          cell: (row) => cellText(row as any, 'desired_plan', 'plan', 'plan_name'),
        },
        AREA_COLUMN,
        STATUS_COLUMN,
        {
          header: 'Referred By',
          secondary: true,
          // Nobody has been to site yet, so there is no technician. Who brought
          // the application in is the attribution that matters at this stage.
          cell: (row) => cellText(row as any, 'referred_by'),
        },
        DATE_COLUMN,
      ];

    case 'repair':
      return [
        NAME_COLUMN,
        ACCOUNT_COLUMN,
        {
          header: 'Repair Category',
          secondary: true,
          cell: (row) => cellText(row as any, 'repair_category'),
        },
        AREA_COLUMN,
        STATUS_COLUMN,
        { header: 'Technician', secondary: true, cell: (row) => cellText(row as any, 'technician') },
        DATE_COLUMN,
      ];

    // Deliberately identical: they are the two "not finished yet" queues and
    // they are read side by side, so the same question is in the same column.
    case 'reschedule':
    case 'pending':
      return [
        NAME_COLUMN,
        {
          key: 'plan',
          header: 'Plan',
          secondary: true,
          cell: (row) => cellText(row as any, 'plan', 'plan_name'),
        },
        AREA_COLUMN,
        STATUS_COLUMN,
        { header: 'Technician', secondary: true, cell: (row) => cellText(row as any, 'technician') },
        REMARKS_COLUMN,
        MODIFIED_COLUMN,
      ];

    default:
      return [
        NAME_COLUMN,
        ACCOUNT_COLUMN,
        {
          key: 'plan',
          header: 'Plan',
          secondary: true,
          cell: (row) => cellText(row as any, 'plan', 'plan_name'),
        },
        AREA_COLUMN,
        STATUS_COLUMN,
        { header: 'Technician', secondary: true, cell: (row) => cellText(row as any, 'technician') },
        DATE_COLUMN,
      ];
  }
};

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
/** One of the two width choices in the layout editor's per-section control. */
const WidthButton: React.FC<{
  active: boolean;
  label: string;
  icon: React.ReactNode;
  onClick: () => void;
}> = ({ active, label, icon, onClick }) => {
  const isDarkMode = useTheme();

  return (
    <button
      type="button"
      role="radio"
      aria-checked={active}
      aria-label={label}
      title={label}
      onClick={onClick}
      className={`rounded p-1 transition-colors ${
        active
          ? isDarkMode
            ? 'bg-blue-400/30 text-blue-100'
            : 'bg-white text-blue-700'
          : isDarkMode
          ? 'text-blue-200/70 hover:text-blue-100'
          : 'text-white/70 hover:text-white'
      }`}
    >
      {icon}
    </button>
  );
};

const Row: React.FC<{ children: React.ReactNode; wide?: boolean }> = ({ children, wide }) => {
  const span = React.useContext(SectionWidth);

  // A block the user has narrowed to half the page cannot hold four or five
  // tiles across — the labels truncate to nothing and the numbers stop being
  // readable at a distance, which is the whole point of this screen. Two across
  // is the widest a half-width block stays legible at.
  const columns = span === 1 ? 'xl:grid-cols-2' : wide ? 'xl:grid-cols-5' : 'xl:grid-cols-4';

  return <div className={`grid grid-cols-1 sm:grid-cols-2 gap-4 ${columns}`}>{children}</div>;
};

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
 *
 * ── Formula tiles ─────────────────────────────────────────────────────
 *
 * `formula` marks a value that is arithmetic over other tiles rather than a
 * total of rows — Net, the projections, the averages. Those are not clickable,
 * and the distinction is not pedantry: a drill-down exists to answer "which
 * ones", and there is no set of records whose members are net income. Every one
 * of these previously opened a modal that showed *something* — usually the
 * financial record list, which is the population behind Income, not behind Net —
 * so the modal quietly answered a different question from the one the tile
 * asked, and its total did not match the tile that opened it.
 *
 * Passing both `formula` and `onOpen` is a contradiction, and `formula` wins:
 * the click is dropped rather than silently rendering a lying modal.
 *
 * A formula tile therefore renders as a div with no pointer cursor, no hover
 * lift and no focus ring — nothing that suggests it does anything — and puts the
 * arithmetic in its tooltip instead, which is the answer somebody clicking it
 * was actually after.
 */
const Tile: React.FC<{
  label: string;
  value: React.ReactNode;
  caption?: string;
  tone?: Tone;
  icon?: React.ReactNode;
  onOpen?: () => void;
  /** The arithmetic behind a derived value. Makes the tile non-interactive. */
  formula?: string;
}> = ({ label, value, caption, tone = 'neutral', icon, onOpen, formula }) => {
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

  // `formula` wins over `onOpen` — see the docblock. Deliberately not an error:
  // a tile that renders inert is a visible, correctable mistake, where a modal
  // showing the wrong population is an invisible one.
  if (formula || !onOpen) {
    return (
      <div className={shell} title={formula ? `Calculated: ${formula}` : undefined}>
        {body}
      </div>
    );
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
