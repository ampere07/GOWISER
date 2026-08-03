import React, { useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  Banknote,
  BarChart3,
  Building2,
  CreditCard,
  Landmark,
  Lock,
  MapPin,
  ShieldAlert,
  TrendingUp,
  Trophy,
  UserCheck,
  Users,
  Wallet,
  Wifi,
  Wrench,
} from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import WidgetRange from '../components/reporting/WidgetRange';
import { ErrorBanner, Pill, RankBadge, Table, Td, Th, Thead, Tr } from '../components/reporting/primitives';
import { RestrictedPanel } from '../components/rbac/Restricted';
import { usePermissions } from '../hooks/usePermissions';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { reportingService } from '../services/reportingService';
import { ExecutiveOverviewData, BarangayRow } from '../types/reporting';
import { formatMoney, formatNumber, pluralise } from '../utils/format';

interface ExecutiveOverviewProps {
  refreshToken: number;
}

/**
 * Redesigned executive dashboard — simplified for a business owner.
 *
 * Three clear sections:
 *   1. Subscribers — status breakdowns, JO/SO tasks, applications, barangay table
 *   2. Finance — income, projected monthly, expenses, gross/net, payment methods, plans
 *   3. Operations — reported concerns, repair categories, top tech leaderboard
 *
 * Default view is daily. Dynamic date filtering, no hardcoded months.
 * Every figure is the same figure the module it came from shows.
 */
const ExecutiveOverview: React.FC<ExecutiveOverviewProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { user } = usePermissions();

  // Default to daily view as requested
  const range = useWidgetRange('daily');

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

  const subs = data?.subscriber_overview;
  const finance = data?.financial_summary;
  const ops = data?.operations_tech;
  const first = loading && !data;

  if (forbidden) {
    return (
      <ReportingPage>
        <PageHeader title="Executive Dashboard" />
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

  return (
    <ReportingPage>
      <PageHeader
        title="Executive Dashboard"
        subtitle={
          <>
            Business overview
            {data && <> · {data.range_label}</>}
          </>
        }
        actions={<WidgetRange state={range} size="md" />}
      />

      {error && <ErrorBanner message={error} />}

      {/* Database availability warning */}
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
              answered. Some data may be incomplete.
            </p>
          </div>
        </div>
      )}

      {/* Projected Monthly Earnings — hero card */}
      {finance?.available && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <KpiCard
            label="Total Income"
            value={first ? '—' : formatMoney(finance?.total_income)}
            icon={<Banknote size={18} />}
            tone="success"
            caption={data?.range_label}
          />
          <KpiCard
            label="Projected Monthly"
            value={first ? '—' : formatMoney(finance?.projected_monthly)}
            icon={<TrendingUp size={18} />}
            tone="info"
            caption={
              finance?.daily_average
                ? `${formatMoney(finance.daily_average)}/day × ${finance.days_in_month ?? 30} days`
                : 'daily avg × days in month'
            }
          />
          <KpiCard
            label="Total Expenses"
            value={first ? '—' : formatMoney(finance?.total_expenses)}
            icon={<Building2 size={18} />}
            tone="warning"
            caption={`OPEX: ${formatMoney(finance?.opex)} · CAPEX: ${formatMoney(finance?.capex)}`}
          />
          <KpiCard
            label="Net Income"
            value={first ? '—' : formatMoney(finance?.net)}
            icon={<Wallet size={18} />}
            tone={(finance?.net ?? 0) >= 0 ? 'success' : 'danger'}
            caption={
              finance?.margin_pct !== null && finance?.margin_pct !== undefined
                ? `${finance.margin_pct.toFixed(1)}% margin`
                : 'income − expenses'
            }
          />
        </div>
      )}

      {/* ── 1. SUBSCRIBERS ───────────────────────────────────────────── */}
      {subs?.available && (
        <Card flush>
          <CardHeader
            title="Subscribers"
            subtitle="Status breakdown across billing, online, and work orders"
            icon={<Users size={16} />}
          />
          <CardBody>
            {/* Billing Status */}
            <SectionLabel label="Billing Status" icon={<UserCheck size={14} />} />
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
              <MiniKpi label="Active" value={first ? '—' : formatNumber(subs.billing?.active)} tone="success" />
              <MiniKpi label="Inactive" value={first ? '—' : formatNumber(subs.billing?.inactive)} tone="warning" />
              <MiniKpi label="VIP" value={first ? '—' : formatNumber(subs.billing?.vip)} tone="info" />
              <MiniKpi label="Pullout" value={first ? '—' : formatNumber(subs.billing?.pullout)} tone="danger" />
            </div>

            {/* Online Status */}
            <SectionLabel label="Online Status" icon={<Wifi size={14} />} />
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
              <MiniKpi label="Online" value={first ? '—' : formatNumber(subs.online_status?.online)} tone="success" />
              <MiniKpi label="Offline" value={first ? '—' : formatNumber(subs.online_status?.offline)} tone="danger" />
              <MiniKpi label="Restricted" value={first ? '—' : formatNumber(subs.online_status?.restricted)} tone="warning" />
              <MiniKpi label="Disconnected" value={first ? '—' : formatNumber(subs.online_status?.disconnected)} tone="neutral" />
            </div>

            {/* JO/SO Status */}
            <SectionLabel label="JO/SO Status" icon={<BarChart3 size={14} />} />
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
              <MiniKpi label="Done" value={first ? '—' : formatNumber(subs.jo_so_status?.done)} tone="success" />
              <MiniKpi label="Reschedule" value={first ? '—' : formatNumber(subs.jo_so_status?.reschedule)} tone="warning" />
              <MiniKpi label="Failed" value={first ? '—' : formatNumber(subs.jo_so_status?.failed)} tone="danger" />
              <MiniKpi label="In Progress" value={first ? '—' : formatNumber(subs.jo_so_status?.in_progress)} tone="info" />
            </div>

            {/* Total Applications */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
              <MiniKpi
                label="Total Applications"
                value={first ? '—' : formatNumber(subs.total_applications)}
                tone="info"
              />
            </div>

            {/* Per Barangay Count Table */}
            {(subs.barangays?.length ?? 0) > 0 && (
              <>
                <SectionLabel label="Per Barangay Count" icon={<MapPin size={14} />} />
                <BarangayTable rows={subs.barangays ?? []} loading={first} />
              </>
            )}
          </CardBody>
        </Card>
      )}

      {/* ── 2. FINANCE ───────────────────────────────────────────────── */}
      {finance?.masked ? (
        <RestrictedPanel title="Finance" height={220} />
      ) : finance?.available ? (
        <Card flush>
          <CardHeader
            title="Finance"
            subtitle={finance.range_label}
            icon={<Wallet size={16} />}
          />
          <CardBody>
            {/* Gross / Net / OPEX / CAPEX */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
              <MiniKpi label="Gross Revenue" value={first ? '—' : formatMoney(finance.gross)} tone="success" />
              <MiniKpi label="Net Revenue" value={first ? '—' : formatMoney(finance.net)} tone={(finance.net ?? 0) >= 0 ? 'success' : 'danger'} />
              <MiniKpi label="OPEX" value={first ? '—' : formatMoney(finance.opex)} tone="warning" />
              <MiniKpi label="CAPEX" value={first ? '—' : formatMoney(finance.capex)} tone="neutral" />
            </div>

            {/* Payment Methods */}
            {(finance.by_method?.length ?? 0) > 0 && (
              <>
                <SectionLabel label="Payment Methods" icon={<CreditCard size={14} />} />
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-5">
                  {finance.by_method?.map((method) => (
                    <div
                      key={method.label}
                      className={`rounded-lg px-3 py-2.5 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
                    >
                      <p className={`text-xs font-medium ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                        {method.label}
                      </p>
                      <p className="text-lg font-bold tabular-nums truncate">
                        {formatMoney(method.total)}
                      </p>
                      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        {pluralise(method.count, 'transaction')}
                      </p>
                    </div>
                  ))}
                </div>
              </>
            )}

            {/* Plan Distribution */}
            {(finance.by_plan?.length ?? 0) > 0 && (
              <>
                <SectionLabel label="Plan Distribution" icon={<BarChart3 size={14} />} />
                <Table>
                  <Thead>
                    <Th>Plan</Th>
                    <Th align="right">Subscribers</Th>
                    <Th align="right">Revenue</Th>
                  </Thead>
                  <tbody>
                    {finance.by_plan?.map((plan) => (
                      <Tr key={plan.label}>
                        <Td className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                          {plan.label}
                        </Td>
                        <Td align="right" className="tabular-nums">
                          {formatNumber(plan.count)}
                        </Td>
                        <Td align="right" className="tabular-nums font-semibold">
                          {formatMoney(plan.total)}
                        </Td>
                      </Tr>
                    ))}
                  </tbody>
                </Table>
              </>
            )}

            {/* Income by Channel */}
            {finance.channels && Object.keys(finance.channels).length > 0 && (
              <>
                <SectionLabel label="Income by Channel" icon={<Banknote size={14} />} />
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  {Object.entries(finance.channels).map(([key, channel]) => (
                    <div
                      key={key}
                      className={`rounded-lg px-3 py-2.5 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
                    >
                      <p className={`flex items-center gap-1.5 text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                        {key === 'cash' ? <Banknote size={14} /> : key === 'pnb' ? <Landmark size={14} /> : <CreditCard size={14} />}
                        {channel.label}
                      </p>
                      <p className="text-lg font-bold tabular-nums truncate">
                        {formatMoney(channel.total)}
                      </p>
                      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        {channel.share_pct?.toFixed(1)}% of income
                      </p>
                    </div>
                  ))}
                </div>
              </>
            )}
          </CardBody>
        </Card>
      ) : null}

      {/* ── 3. OPERATIONS ────────────────────────────────────────────── */}
      {ops?.available && (
        <Card flush>
          <CardHeader
            title="Operations"
            subtitle="Field work summary"
            icon={<Wrench size={16} />}
          />
          <CardBody>
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {/* Reported Concerns */}
              <div>
                <SectionLabel label="Reported Concerns" icon={<ShieldAlert size={14} />} />
                {(ops.concerns?.length ?? 0) === 0 ? (
                  <EmptyState label="No concerns reported" />
                ) : (
                  <div className="space-y-2">
                    {ops.concerns?.map((concern) => (
                      <div
                        key={concern.label}
                        className={`flex items-center justify-between rounded-lg px-3 py-2 ${
                          isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'
                        }`}
                      >
                        <span className={`text-sm ${isDarkMode ? 'text-gray-200' : 'text-gray-700'}`}>
                          {concern.label}
                        </span>
                        <Pill tone="warning">{formatNumber(concern.count)}</Pill>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              {/* Repair Categories */}
              <div>
                <SectionLabel label="Repair Categories" icon={<Wrench size={14} />} />
                {(ops.repair_categories?.length ?? 0) === 0 ? (
                  <EmptyState label="No repair records" />
                ) : (
                  <div className="space-y-2">
                    {ops.repair_categories?.map((cat) => (
                      <div
                        key={cat.label}
                        className={`flex items-center justify-between rounded-lg px-3 py-2 ${
                          isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'
                        }`}
                      >
                        <span className={`text-sm ${isDarkMode ? 'text-gray-200' : 'text-gray-700'}`}>
                          {cat.label}
                        </span>
                        <Pill tone="info">{formatNumber(cat.count)}</Pill>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>

            {/* Top Tech Leaderboard */}
            {(ops.top_tech?.length ?? 0) > 0 && (
              <div className="mt-6">
                <SectionLabel label="Top Technicians" icon={<Trophy size={14} />} />
                <Table>
                  <Thead>
                    <Th width="40px">#</Th>
                    <Th>Technician</Th>
                    <Th align="right">JO Done</Th>
                    <Th align="right">SO Done</Th>
                    <Th align="right">Total</Th>
                    <Th align="right">Avg Time</Th>
                  </Thead>
                  <tbody>
                    {ops.top_tech?.map((tech, idx) => (
                      <Tr key={tech.id ?? tech.name}>
                        <Td>
                          <RankBadge rank={idx + 1} />
                        </Td>
                        <Td className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                          {tech.name}
                        </Td>
                        <Td align="right" className="tabular-nums">
                          {formatNumber(tech.job_orders_done)}
                        </Td>
                        <Td align="right" className="tabular-nums">
                          {formatNumber(tech.service_orders_done)}
                        </Td>
                        <Td align="right" className="font-semibold tabular-nums">
                          {formatNumber(tech.completed)}
                        </Td>
                        <Td align="right" className={`tabular-nums ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                          {tech.average_minutes !== null && tech.average_minutes !== undefined
                            ? `${tech.average_minutes} min`
                            : '—'}
                        </Td>
                      </Tr>
                    ))}
                  </tbody>
                </Table>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {/* System Alarms — kept but simplified */}
      {(ops?.alarms?.length ?? 0) > 0 && (
        <Card flush>
          <CardHeader
            title="System Alerts"
            icon={<ShieldAlert size={16} />}
          />
          <CardBody>
            <div className="space-y-2">
              {ops?.alarms.map((alarm) => (
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
                      <Pill
                        tone={
                          alarm.severity === 'critical'
                            ? 'danger'
                            : alarm.severity === 'warning'
                            ? 'warning'
                            : 'info'
                        }
                      >
                        {alarm.severity}
                      </Pill>
                    </p>
                    <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                      {alarm.detail}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          </CardBody>
        </Card>
      )}

      <p className={`text-xs ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
        Figures sourced from section modules. Counts as of {data?.as_of ?? 'today'}.
      </p>
    </ReportingPage>
  );
};

// ── Sub-components ────────────────────────────────────────────────────

type Tone = 'success' | 'danger' | 'warning' | 'neutral' | 'info';

/** Hero KPI card — large number with icon badge and caption. */
const KpiCard: React.FC<{
  label: string;
  value: React.ReactNode;
  icon?: React.ReactNode;
  caption?: React.ReactNode;
  tone?: Tone;
}> = ({ label, value, icon, caption, tone = 'neutral' }) => {
  const isDarkMode = useTheme();

  const toneValue: Record<Tone, string> = {
    success: 'text-emerald-600 dark:text-emerald-400',
    danger: 'text-red-500 dark:text-red-400',
    warning: 'text-amber-500 dark:text-amber-400',
    neutral: isDarkMode ? 'text-white' : 'text-gray-900',
    info: 'text-blue-600 dark:text-blue-400',
  };

  const toneBg: Record<Tone, string> = {
    success: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
    danger: 'bg-red-100 text-red-500 dark:bg-red-500/15 dark:text-red-400',
    warning: 'bg-amber-100 text-amber-500 dark:bg-amber-500/15 dark:text-amber-400',
    neutral: 'bg-gray-100 text-gray-500 dark:bg-gray-700/60 dark:text-gray-300',
    info: 'bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
  };

  return (
    <div
      className={`rounded-xl border p-4 sm:p-5 ${
        isDarkMode ? 'bg-gray-900 border-gray-800' : 'bg-white border-gray-200 shadow-sm'
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className={`text-sm mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{label}</p>
          <p className={`text-2xl sm:text-3xl font-bold tracking-tight truncate ${toneValue[tone]}`}>
            {value}
          </p>
        </div>
        {icon && (
          <span className={`flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center ${toneBg[tone]}`}>
            {icon}
          </span>
        )}
      </div>
      {caption && (
        <p className={`mt-2 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>{caption}</p>
      )}
    </div>
  );
};

/** Small count tile inside a section. */
const MiniKpi: React.FC<{
  label: string;
  value: React.ReactNode;
  tone?: Tone;
}> = ({ label, value, tone = 'neutral' }) => {
  const isDarkMode = useTheme();

  const toneValue: Record<Tone, string> = {
    success: 'text-emerald-600 dark:text-emerald-400',
    danger: 'text-red-500 dark:text-red-400',
    warning: 'text-amber-500 dark:text-amber-400',
    neutral: isDarkMode ? 'text-white' : 'text-gray-900',
    info: 'text-blue-600 dark:text-blue-400',
  };

  return (
    <div className={`rounded-lg px-3 py-2.5 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}>
      <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>{label}</p>
      <p className={`text-xl font-bold tabular-nums truncate mt-0.5 ${toneValue[tone]}`}>{value}</p>
    </div>
  );
};

/** Section label within a card. */
const SectionLabel: React.FC<{ label: string; icon?: React.ReactNode }> = ({ label, icon }) => {
  const isDarkMode = useTheme();

  return (
    <p
      className={`flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider mb-2 mt-1 ${
        isDarkMode ? 'text-gray-400' : 'text-gray-500'
      }`}
    >
      {icon}
      {label}
    </p>
  );
};

/** Empty state for a list. */
const EmptyState: React.FC<{ label: string }> = ({ label }) => {
  const isDarkMode = useTheme();
  return (
    <p className={`text-sm py-4 text-center ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
      {label}
    </p>
  );
};

/** Compact barangay count table. */
const BarangayTable: React.FC<{ rows: BarangayRow[]; loading?: boolean }> = ({ rows, loading }) => {
  const isDarkMode = useTheme();

  // Sort by total descending
  const sorted = [...rows].sort((a, b) => b.total - a.total);

  return (
    <div className="overflow-x-auto max-h-[400px] overflow-y-auto">
      <table className="w-full text-sm">
        <thead className={`sticky top-0 z-10 ${isDarkMode ? 'bg-gray-800/90' : 'bg-gray-50'}`}>
          <tr>
            <th className={`px-3 py-2 text-left font-semibold whitespace-nowrap ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
              Barangay
            </th>
            <th className={`px-3 py-2 text-right font-semibold whitespace-nowrap ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
              Active
            </th>
            <th className={`px-3 py-2 text-right font-semibold whitespace-nowrap ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
              VIP
            </th>
            <th className={`px-3 py-2 text-right font-semibold whitespace-nowrap ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
              Inactive
            </th>
            <th className={`px-3 py-2 text-right font-semibold whitespace-nowrap ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
              Total
            </th>
          </tr>
        </thead>
        <tbody>
          {sorted.map((row) => (
            <tr
              key={`${row.barangay}-${row.municipality}`}
              className={`border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-100'}`}
            >
              <td className={`px-3 py-2 font-medium ${isDarkMode ? 'text-gray-200' : 'text-gray-800'}`}>
                {row.barangay}
                {row.municipality && (
                  <span className={`text-xs ml-1 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                    {row.municipality}
                  </span>
                )}
              </td>
              <td className="px-3 py-2 text-right tabular-nums">{formatNumber(row.active)}</td>
              <td className="px-3 py-2 text-right tabular-nums">{formatNumber(row.vip)}</td>
              <td className="px-3 py-2 text-right tabular-nums">{formatNumber(row.inactive)}</td>
              <td className={`px-3 py-2 text-right tabular-nums font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {formatNumber(row.total)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default ExecutiveOverview;
