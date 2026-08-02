import React, { useCallback, useEffect, useState } from 'react';
import {
  Activity,
  AlertTriangle,
  Banknote,
  Building2,
  CreditCard,
  HardDrive,
  Landmark,
  Lock,
  Radio,
  Receipt,
  ShieldAlert,
  Timer,
  TrendingDown,
  TrendingUp,
  UserCheck,
  Users,
  Wallet,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import WidgetRange from '../components/reporting/WidgetRange';
import { ErrorBanner, Pill, Table, Td, Th, Thead, Tr } from '../components/reporting/primitives';
import { RestrictedPanel } from '../components/rbac/Restricted';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { reportingService } from '../services/reportingService';
import { ExecutiveOverviewData, SystemAlarm } from '../types/reporting';
import { formatMoney, formatNumber, formatPercent, pluralise } from '../utils/format';

interface ExecutiveOverviewProps {
  refreshToken: number;
}

const SEVERITY_TONE: Record<SystemAlarm['severity'], 'danger' | 'warning' | 'info'> = {
  critical: 'danger',
  warning: 'warning',
  info: 'info',
};

const CHANNEL_ICON: Record<string, React.ReactNode> = {
  cash: <Banknote size={14} />,
  pnb: <Landmark size={14} />,
  portal: <CreditCard size={14} />,
  other: <Wallet size={14} />,
};

/**
 * Module 5 — the consolidated C-suite summary.
 *
 * Every figure here is the same figure the module it came from shows, arrived at
 * through the same code path and the same cache. The backend composes this from
 * the existing section payloads rather than querying independently, precisely so
 * a board meeting is never spent reconciling two of our own numbers.
 *
 * Guarded twice on the server: the module permission decides whether the tab
 * exists, and a role check decides whether this particular view is appropriate
 * at all. A 403 here is therefore a real answer, not a bug, and is rendered as
 * one rather than as a generic failure.
 */
const ExecutiveOverview: React.FC<ExecutiveOverviewProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { user } = usePermissions();

  const range = useWidgetRange('monthly');

  const [data, setData] = useState<ExecutiveOverviewData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const load = useCallback(() => {
    let cancelled = false;

    setLoading(true);

    reportingService
      .getExecutiveOverview({ dateFrom: range.range.from, dateTo: range.range.to })
      .then((result) => {
        if (cancelled) return;

        setData(result);
        setError(null);
        setForbidden(false);
      })
      .catch((err) => {
        if (cancelled) return;

        // A 403 is the role check answering, not a failure. Separated so the
        // page can say what it means instead of offering a retry that will
        // never succeed.
        if (err?.response?.status === 403) {
          setForbidden(true);
          setError(null);
          return;
        }

        setError(
          err?.response?.data?.message ?? 'Unable to build the executive summary right now.'
        );
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [range.range.from, range.range.to]);

  useEffect(() => load(), [load, refreshToken]);

  const health = data?.subscriber_health;
  const finance = data?.financial_summary;
  const ops = data?.operations_tech;
  const first = loading && !data;

  if (forbidden) {
    return (
      <ReportingPage>
        <PageHeader title="Executive Group Overview" />
        <Card>
          <div className="flex items-start gap-3 py-6 px-2">
            <Lock size={20} className={isDarkMode ? 'text-gray-600' : 'text-gray-400'} />
            <div>
              <p className={`font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                Restricted to executive roles
              </p>
              <p className={`text-sm mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                This view puts every company's subscribers, money and backlog on one screen, so it
                requires an executive role in addition to the module permission. Your role
                {user?.role ? <> ({user.role})</> : null} does not have it.
              </p>
            </div>
          </div>
        </Card>
      </ReportingPage>
    );
  }

  return (
    <ReportingPage>
      <PageHeader
        title="Executive Group Overview"
        subtitle={
          <>
            Group-wide summary
            {data && <> · {data.range_label}</>}
            {data && data.databases.total > 0 && (
              <> · {pluralise(data.databases.answered.length, 'database')}</>
            )}
          </>
        }
        actions={<WidgetRange state={range} size="md" />}
      />

      {error && <ErrorBanner message={error} />}

      {/* A summary built on six of eight branches is not wrong, but it must not
          be read as eight — so the shortfall travels with the figures. */}
      {data && data.databases.failed.length > 0 && (
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
              <strong>
                {data.databases.answered.length} of {data.databases.total} databases
              </strong>{' '}
              answered, so these totals are incomplete.
            </p>
            <ul className="mt-1 space-y-0.5">
              {data.databases.failed.map((entry) => (
                <li key={entry.key} className="break-words">
                  <strong>{entry.label}</strong> — {entry.error}
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}

      {/* Sections that could not be reached are named rather than shown as zero:
          "no revenue" and "we could not ask" are different claims. */}
      {data && Object.keys(data.unavailable).length > 0 && (
        <div
          className={`flex items-start gap-2 rounded-xl border px-3 py-2 text-sm ${
            isDarkMode
              ? 'border-gray-800 bg-gray-900 text-gray-400'
              : 'border-gray-200 bg-gray-50 text-gray-600'
          }`}
        >
          <ShieldAlert size={15} className="mt-0.5 flex-shrink-0" />
          <span>
            Not included in this summary:{' '}
            {Object.keys(data.unavailable)
              .map((key) => key.replace(/_/g, ' '))
              .join(', ')}
            .
          </span>
        </div>
      )}

      {/* ── 1. Subscriber health ──────────────────────────────────────── */}
      <Card flush>
        <CardHeader
          title="Subscriber Health"
          subtitle={health?.range_label}
          icon={<Users size={16} />}
        />
        <CardBody>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <Tile
              label="Active Subscribers"
              value={first ? '—' : formatNumber(health?.active_subscribers)}
              icon={<UserCheck size={16} />}
              caption={
                health?.vip_subscribers
                  ? `${formatNumber(health.vip_subscribers)} VIP included`
                  : 'active and VIP'
              }
            />
            {/* New minus disconnections, so a month that signed 40 and lost 45
                reads as minus five rather than as growth of 40. */}
            <Tile
              label="Net Growth"
              value={
                first || health?.net_growth === undefined
                  ? '—'
                  : `${health.net_growth > 0 ? '+' : ''}${formatNumber(health.net_growth)}`
              }
              icon={
                (health?.net_growth ?? 0) >= 0 ? <TrendingUp size={16} /> : <TrendingDown size={16} />
              }
              tone={(health?.net_growth ?? 0) >= 0 ? 'success' : 'danger'}
              caption={
                health
                  ? `${formatNumber(health.new_in_range)} new · ${formatNumber(
                      health.disconnected
                    )} disconnected`
                  : undefined
              }
            />
            <Tile
              label="Churn Rate"
              value={first ? '—' : formatPercent(health?.churn_rate_pct)}
              icon={<TrendingDown size={16} />}
              tone={(health?.churn_rate_pct ?? 0) > 10 ? 'danger' : 'neutral'}
              caption="disconnected ÷ (active + disconnected)"
            />
            {/* Read out of the card list rather than a fixed field: the billing
                summary is now whatever statuses the source holds, and an install
                without a Pullout status shows a dash instead of a false zero. */}
            <Tile
              label="Pullout"
              value={
                first
                  ? '—'
                  : formatNumber(
                      health?.billing_summary?.cards.find(
                        (card) => card.key === 'pullout'
                      )?.count
                    )
              }
              icon={<Users size={16} />}
              caption="equipment recovered"
            />
          </div>
        </CardBody>
      </Card>

      {/* ── 2. Financial summary ──────────────────────────────────────── */}
      {finance?.masked ? (
        <RestrictedPanel title="Financial Summary" height={220} />
      ) : (
        <Card flush>
          <CardHeader
            title="Financial Summary"
            subtitle={finance?.range_label}
            icon={<Wallet size={16} />}
          />
          <CardBody>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
              <Tile
                label="Total Income"
                value={first ? '—' : formatMoney(finance?.total_income)}
                icon={<Banknote size={16} />}
                tone="success"
                caption={finance ? `${formatPercent(finance.margin_pct)} margin` : undefined}
              />
              <Tile
                label="OpEx"
                value={first ? '—' : formatMoney(finance?.opex)}
                icon={<Building2 size={16} />}
                caption="consumed in period"
              />
              <Tile
                label="CapEx"
                value={first ? '—' : formatMoney(finance?.capex)}
                icon={<HardDrive size={16} />}
                caption="assets acquired"
              />
              <Tile
                label="Outstanding Payables"
                value={first ? '—' : formatMoney(finance?.outstanding_payables)}
                icon={<Receipt size={16} />}
                tone={(finance?.outstanding_payables ?? 0) > 0 ? 'danger' : 'success'}
                caption={
                  finance ? pluralise(finance.payables_unpaid_count ?? 0, 'item') + ' unsettled' : undefined
                }
              />
            </div>

            {/* Income by channel — the split finance reconciles against. */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              {['cash', 'pnb', 'portal'].map((key) => {
                const channel = finance?.channels?.[key];

                return (
                  <div
                    key={key}
                    className={`rounded-lg px-3 py-2.5 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
                  >
                    <p
                      className={`flex items-center gap-1.5 text-xs ${
                        isDarkMode ? 'text-gray-400' : 'text-gray-500'
                      }`}
                    >
                      {CHANNEL_ICON[key]}
                      {channel?.label ?? key.toUpperCase()}
                    </p>
                    <p className="text-lg font-bold tabular-nums truncate">
                      {first ? '—' : formatMoney(channel?.total ?? 0)}
                    </p>
                    <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                      {formatPercent(channel?.share_pct ?? 0)} of income
                    </p>
                  </div>
                );
              })}
            </div>

            {finance?.metrics && (
              <div
                className={`mt-4 pt-4 border-t grid grid-cols-2 lg:grid-cols-4 gap-3 ${
                  isDarkMode ? 'border-gray-800' : 'border-gray-200'
                }`}
              >
                {[
                  { metric: finance.metrics.prospective_revenue, money: true },
                  { metric: finance.metrics.arpu, money: true },
                  { metric: finance.metrics.collection_efficiency, money: false },
                  { metric: finance.metrics.projected_churn_loss, money: true },
                ].map(({ metric, money }) => (
                  <div key={metric.label}>
                    <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                      {metric.label}
                    </p>
                    <p className="text-base font-bold tabular-nums truncate">
                      {metric.value === null
                        ? '—'
                        : money
                        ? formatMoney(metric.value)
                        : formatPercent(metric.value)}
                    </p>
                    {/* The assumption travels with the number: three of these
                        four are projections, and one shown with the authority of
                        a measurement is how a dashboard misleads. */}
                    <p
                      className={`text-[11px] leading-snug ${
                        isDarkMode ? 'text-gray-600' : 'text-gray-400'
                      }`}
                    >
                      {metric.basis}
                    </p>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {/* ── 3. Operations and tech ────────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card flush>
          <CardHeader
            title="Operations & Tech"
            subtitle="Delivery and field capacity"
            icon={<Activity size={16} />}
          />
          <CardBody>
            <div className="grid grid-cols-2 gap-4">
              <Tile
                label="Avg. Resolution"
                value={
                  first || ops?.average_turnaround === null || ops?.average_turnaround === undefined
                    ? '—'
                    : `${ops.average_turnaround} ${ops.turnaround_unit === 'hours' ? 'h' : 'min'}`
                }
                icon={<Timer size={16} />}
                caption={
                  ops?.turnaround_unit === 'hours' ? 'ticket age, closed work' : 'time on site'
                }
              />
              <Tile
                label="Open Work"
                value={first ? '—' : formatNumber(ops?.open_work)}
                icon={<Activity size={16} />}
                tone={(ops?.open_work ?? 0) > 0 ? 'warning' : 'success'}
                caption={
                  ops?.oldest_open_days !== null && ops?.oldest_open_days !== undefined
                    ? `oldest ${formatNumber(ops.oldest_open_days)}d`
                    : 'nothing waiting'
                }
              />
              <Tile
                label="Technicians Live"
                value={
                  first || ops?.technicians_live === null
                    ? '—'
                    : `${formatNumber(ops?.technicians_live)}/${formatNumber(
                        ops?.technicians_reporting
                      )}`
                }
                icon={<Radio size={16} />}
                tone={(ops?.technicians_live ?? 0) > 0 ? 'success' : 'neutral'}
                caption="devices reporting recently"
              />
              <Tile
                label="Active Alarms"
                value={first ? '—' : formatNumber(ops?.alarm_count)}
                icon={<ShieldAlert size={16} />}
                tone={(ops?.alarm_count ?? 0) > 0 ? 'danger' : 'success'}
                caption={(ops?.alarm_count ?? 0) > 0 ? 'see the list beside' : 'nothing raised'}
              />
            </div>
          </CardBody>
        </Card>

        <Card flush>
          <CardHeader
            title="Active System Alarms"
            subtitle={
              // States the floor whenever an age was clamped, so "40 days" on a
              // job that plainly predates the migration is never read as its
              // true age.
              ops?.age_bounded && ops.reliable_from
                ? `Ages counted from ${ops.reliable_from} — earlier records are not reliable`
                : 'Derived from operational thresholds, not a monitoring feed'
            }
            icon={<ShieldAlert size={16} />}
          />
          <CardBody>
            {(ops?.alarms.length ?? 0) === 0 ? (
              <p className={`text-sm py-6 text-center ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                {first ? 'Loading…' : 'Nothing is currently raising an alarm.'}
              </p>
            ) : (
              <div className="space-y-2">
                {(ops?.alarms ?? []).map((alarm) => (
                  <div
                    key={alarm.key}
                    className={`rounded-lg px-3 py-2 flex items-start gap-2 ${
                      isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'
                    }`}
                  >
                    <AlertTriangle
                      size={15}
                      className={`mt-0.5 flex-shrink-0 ${
                        alarm.severity === 'critical'
                          ? 'text-red-500'
                          : alarm.severity === 'warning'
                          ? 'text-amber-500'
                          : 'text-blue-500'
                      }`}
                    />
                    <div className="min-w-0">
                      <p className="flex items-center gap-2 text-sm font-semibold">
                        {alarm.label}
                        <Pill tone={SEVERITY_TONE[alarm.severity]}>{alarm.severity}</Pill>
                      </p>
                      {/* Each alarm states what triggered it, so nobody reads a
                          derived threshold as an SNMP trap. */}
                      <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                        {alarm.detail}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>
      </div>

      {/* Turnaround by work type, the actionable half of the SLA picture. */}
      {(ops?.turnaround_by_type.length ?? 0) > 0 && (
        <Card flush>
          <CardHeader title="Resolution Turnaround by Work Type" icon={<Timer size={16} />} />
          <Table>
            <Thead>
              <Th>Work type</Th>
              <Th align="right">Closed</Th>
              <Th align="right">Average</Th>
              <Th align="right">Longest</Th>
            </Thead>
            <tbody>
              {(ops?.turnaround_by_type ?? []).map((row) => {
                const minutes = row.unit === 'minutes';
                const average = minutes ? row.average_minutes : row.average_hours;
                const longest = minutes ? row.longest_minutes : row.longest_hours;
                const unit = minutes ? 'min' : 'h';

                return (
                  <Tr key={`${row.label}-${row.unit}`}>
                    <Td className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                      {row.label}
                    </Td>
                    <Td align="right" className="tabular-nums">
                      {formatNumber(row.closed)}
                    </Td>
                    <Td align="right" className="font-semibold tabular-nums">
                      {average === null || average === undefined ? '—' : `${average} ${unit}`}
                    </Td>
                    <Td
                      align="right"
                      className={`tabular-nums ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}
                    >
                      {longest === null || longest === undefined
                        ? '—'
                        : `${formatNumber(longest)} ${unit}`}
                    </Td>
                  </Tr>
                );
              })}
            </tbody>
          </Table>
        </Card>
      )}

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Every figure here is composed from the same section payloads the modules themselves render,
        so this page cannot disagree with them. Counts are as of {data?.as_of ?? 'today'}. Alarms are
        derived from operational thresholds — aged backlog, silent field devices, unattributed work —
        and are not a network monitoring feed.
      </p>
    </ReportingPage>
  );
};

/** One measure on the summary. Compact by design: this page is read at a glance. */
const Tile: React.FC<{
  label: string;
  value: React.ReactNode;
  icon?: React.ReactNode;
  caption?: React.ReactNode;
  tone?: 'success' | 'danger' | 'warning' | 'neutral';
}> = ({ label, value, icon, caption, tone = 'neutral' }) => {
  const isDarkMode = useTheme();

  const toneClass: Record<string, string> = {
    success: 'text-emerald-600 dark:text-emerald-400',
    danger: 'text-red-500 dark:text-red-400',
    warning: 'text-amber-500 dark:text-amber-400',
    neutral: isDarkMode ? 'text-white' : 'text-gray-900',
  };

  return (
    <div>
      <p
        className={`flex items-center gap-1.5 text-xs ${
          isDarkMode ? 'text-gray-400' : 'text-gray-500'
        }`}
      >
        {icon}
        {label}
      </p>
      <p className={`text-2xl font-bold tracking-tight tabular-nums truncate mt-0.5 ${toneClass[tone]}`}>
        {value}
      </p>
      {caption && (
        <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>{caption}</p>
      )}
    </div>
  );
};

export default ExecutiveOverview;
