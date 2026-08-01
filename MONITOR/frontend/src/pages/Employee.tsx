import React from 'react';
import { Banknote, HardHat, UserCog, Users } from 'lucide-react';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import StatCard from '../components/reporting/StatCard';
import BreakdownTable from '../components/reporting/BreakdownTable';
import { DonutChart } from '../components/reporting/charts';
import {
  Bar,
  ErrorBanner,
  PanelState,
  Pill,
  Table,
  TableState,
  Td,
  Th,
  Thead,
  TotalRow,
  Tr,
} from '../components/reporting/primitives';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice, SourceCell } from '../components/reporting/DatabaseFilter';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { reportingService } from '../services/reportingService';
import { EmployeeData } from '../types/reporting';
import { formatMoney, formatNumber, pluralise } from '../utils/format';

interface EmployeeProps {
  refreshToken: number;
}

/** Marks the driver's placeholder labels so they read as absences, not names. */
const isPlaceholder = (label: string): boolean =>
  label === '(unattributed)' || label === '(unassigned)';

/**
 * Employee — staff and what they produced.
 *
 * Three questions kept deliberately apart rather than merged into one
 * "productivity" score: cashiers collect money, field users close jobs, and
 * payees receive money. Only the first two are staff performance. The payee
 * ledger is spending, and it appears here because that is where the source system
 * records a person's name against an expense — it is not a performance measure and
 * is labelled accordingly.
 */
const Employee: React.FC<EmployeeProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { filters, update, reset, branches, databases } = useSectionFilters('employee');

  const { data, loading, error, sourceLabel, substituted } = useReportingSection<EmployeeData>(
    reportingService.getEmployee,
    filters,
    refreshToken
  );

  const first = loading && !data;

  const roster = data?.roster ?? [];
  const activeStaff = roster.filter((member) => member.active).length;

  const collections = data?.collections ?? [];
  const collected = collections.reduce((sum, row) => sum + row.total, 0);
  const topCollector = collections.find((row) => !isPlaceholder(row.label));

  const fieldWork = data?.field_work ?? [];
  const assigned = fieldWork.reduce((sum, row) => sum + row.assigned, 0);
  const completed = fieldWork.reduce((sum, row) => sum + row.completed, 0);
  const busiest = fieldWork.reduce((max, row) => Math.max(max, row.assigned), 0);

  // Only in aggregate mode. Staff are per-branch people, so their rows are
  // concatenated rather than merged by name.
  const showSource = collections.some((row) => Boolean(row.source_label));

  const branchLabel = data?.branch_label;
  const showBranchLabel =
    branchLabel && branchLabel !== 'All branches' && branchLabel !== 'All accounts';

  return (
    <ReportingPage>
      <PageHeader
        title="Employee"
        subtitle={
          <>
            Staff and what they produced
            {sourceLabel && <> · {sourceLabel}</>}
            {showBranchLabel && <> · {branchLabel}</>}
          </>
        }
      />

      <SourceNotice show={substituted} sourceLabel={sourceLabel} />
      <AggregateNotice aggregate={data?.aggregate} />
      {error && <ErrorBanner message={error} />}

      <FilterBar
        filters={filters}
        onChange={update}
        onReset={reset}
        branches={branches}
        databases={databases}
        showBranch={branches.length > 0}
      />

      {/* ── Headline ──────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Staff"
          value={formatNumber(roster.length)}
          tone="info"
          icon={<Users size={20} />}
          loading={first}
          caption={`${formatNumber(activeStaff)} active`}
        />
        <StatCard
          label="Collected in range"
          value={formatMoney(collected)}
          tone="success"
          icon={<Banknote size={20} />}
          loading={first}
          caption={data?.range_label}
        />
        <StatCard
          label="Top collector"
          value={topCollector ? topCollector.label : '—'}
          tone="neutral"
          icon={<UserCog size={20} />}
          loading={first}
          caption={topCollector ? formatMoney(topCollector.total) : 'nothing attributed'}
        />
        <StatCard
          label="Field jobs closed"
          value={formatNumber(completed)}
          tone="success"
          icon={<HardHat size={20} />}
          loading={first}
          caption={
            assigned > 0
              ? `of ${formatNumber(assigned)} assigned (${Math.round((completed / assigned) * 100)}%)`
              : 'nothing assigned in this range'
          }
        />
      </div>

      {/* ── Composition ───────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
        <Card flush className="lg:col-span-2">
          <CardHeader title="Staff by Role" />
          <CardBody>
            <PanelState
              loading={first}
              empty={(data?.by_role.length ?? 0) === 0}
              emptyMessage="No staff accounts on file."
              height={280}
            >
              <DonutChart
                labels={(data?.by_role ?? []).map((role) => role.label)}
                values={(data?.by_role ?? []).map((role) => role.count)}
                unit="count"
                height={280}
              />
            </PanelState>
          </CardBody>
        </Card>

        <Card flush className="lg:col-span-3">
          <CardHeader title="Roles" subtitle="Accounts per role, and how many are active" />
          <Table>
            <Thead>
              <Th>Role</Th>
              <Th align="right">Accounts</Th>
              <Th align="right">Active</Th>
              <Th width="90px" />
            </Thead>
            <tbody>
              <TableState
                colSpan={4}
                loading={first}
                error={error}
                empty={(data?.by_role.length ?? 0) === 0}
                emptyMessage="No staff accounts on file."
              />

              {(data?.by_role ?? []).map((role) => (
                <Tr key={role.label}>
                  <Td className={`font-medium capitalize ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                    {role.label}
                  </Td>
                  <Td align="right" className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                    {formatNumber(role.count)}
                  </Td>
                  <Td align="right" className="text-emerald-600 dark:text-emerald-400">
                    {formatNumber(role.active)}
                  </Td>
                  <Td>
                    {/* Share of the role that is active — a role with many
                        disabled accounts is a housekeeping signal. */}
                    <Bar
                      pct={role.count > 0 ? (role.active / role.count) * 100 : 0}
                      color="#198754"
                    />
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </Card>
      </div>

      {/* ── Collections per cashier ───────────────────────────────────── */}
      <Card flush>
        <CardHeader title="Collections by Staff" subtitle={data?.range_label} />
        <Table>
          <Thead>
            {showSource && <Th>Database</Th>}
            <Th>Staff</Th>
            <Th>Role</Th>
            <Th align="right">Payments</Th>
            <Th align="right">Collected</Th>
            <Th width="90px" />
          </Thead>
          <tbody>
            <TableState
              colSpan={showSource ? 6 : 5}
              loading={first}
              error={error}
              empty={collections.length === 0}
              emptyMessage="No collections in this range."
            />

            {collections.map((row) => (
              <Tr key={`${row.source ?? ''}-${row.label}-${row.role}`}>
                {showSource && (
                  <Td>
                    <SourceCell label={row.source_label} />
                  </Td>
                )}
                <Td
                  className={
                    isPlaceholder(row.label)
                      ? `italic ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`
                      : `font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`
                  }
                >
                  {row.label}
                </Td>
                <Td className={`capitalize ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                  {row.role || '—'}
                </Td>
                <Td align="right" className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                  {formatNumber(row.count)}
                </Td>
                <Td align="right" className="font-semibold text-emerald-600 dark:text-emerald-400">
                  {formatMoney(row.total)}
                </Td>
                <Td>
                  <Bar pct={collected > 0 ? (row.total / collected) * 100 : 0} color="#198754" />
                </Td>
              </Tr>
            ))}

            {collections.length > 0 && (
              <TotalRow>
                {showSource && <Td />}
                <Td>Total</Td>
                <Td />
                <Td align="right">
                  {formatNumber(collections.reduce((sum, row) => sum + row.count, 0))}
                </Td>
                <Td align="right" className="text-emerald-600 dark:text-emerald-400">
                  {formatMoney(collected)}
                </Td>
                <Td />
              </TotalRow>
            )}
          </tbody>
        </Table>
      </Card>

      {/* ── Field work per user ───────────────────────────────────────── */}
      <Card flush>
        <CardHeader title="Field Work by Staff" subtitle={data?.range_label} />
        <Table>
          <Thead>
            <Th>Staff</Th>
            <Th>Role</Th>
            <Th align="right">Assigned</Th>
            <Th align="right">Completed</Th>
            <Th align="right">Avg. turnaround</Th>
            <Th width="90px" />
          </Thead>
          <tbody>
            <TableState
              colSpan={6}
              loading={first}
              error={error}
              empty={fieldWork.length === 0}
              emptyMessage="No field work was assigned in this range."
            />

            {fieldWork.map((row) => (
              <Tr key={`${row.source ?? ''}-${row.label}-${row.role}`}>
                <Td
                  className={
                    isPlaceholder(row.label)
                      ? `italic ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`
                      : `font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`
                  }
                >
                  {row.label}
                </Td>
                <Td className={`capitalize ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                  {row.role || '—'}
                </Td>
                <Td align="right" className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                  {formatNumber(row.assigned)}
                </Td>
                <Td align="right" className="font-semibold text-emerald-600 dark:text-emerald-400">
                  {formatNumber(row.completed)}
                </Td>
                <Td align="right" className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                  {row.average_hours === null ? '—' : `${row.average_hours}h`}
                </Td>
                <Td>
                  <Bar pct={busiest > 0 ? (row.assigned / busiest) * 100 : 0} color="#0d6efd" />
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>

      {/* ── Payee ledger, where the source keeps one ───────────────────── */}
      {data?.supports_payees ? (
        <BreakdownTable
          title="Payments to Employees & Payees"
          labelHeader="Payee"
          countLabel="Entries"
          totalLabel="Paid out"
          rows={data.payees}
          loading={first}
          error={error}
          emptyMessage="No expenses were recorded against a payee in this range."
          showTotal
        />
      ) : (
        <Card>
          <p className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
            This system keeps no expense ledger, so there are no payee records to show. Switch to a
            system that tracks expenses to see money paid out to staff.
          </p>
        </Card>
      )}

      {/* ── Roster ───────────────────────────────────────────────────── */}
      <Card flush>
        <CardHeader title="Staff Roster" badge={pluralise(roster.length, 'account')} />
        <Table>
          <Thead>
            <Th>Name</Th>
            <Th>Username</Th>
            <Th>Email</Th>
            <Th>Role</Th>
            {branches.length > 0 && <Th>Branch</Th>}
            <Th align="right">Status</Th>
          </Thead>
          <tbody>
            <TableState
              colSpan={branches.length > 0 ? 6 : 5}
              loading={first}
              error={error}
              empty={roster.length === 0}
              emptyMessage="No staff accounts on file."
            />

            {roster.map((member) => (
              <Tr key={member.id}>
                <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {member.name}
                </Td>
                <Td className={`font-mono text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                  {member.username || '—'}
                </Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                  {member.email || '—'}
                </Td>
                <Td className={`capitalize ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                  {member.role || '—'}
                </Td>
                {branches.length > 0 && (
                  <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                    {member.branch || '—'}
                  </Td>
                )}
                <Td align="right">
                  <Pill tone={member.active ? 'success' : 'neutral'}>
                    {member.active ? 'Active' : 'Disabled'}
                  </Pill>
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Collections are credited to the account that recorded the payment, not to whoever took the
        cash. Work recorded against a deleted or missing account appears as unattributed rather than
        being dropped, so these totals reconcile with the Financial section.
      </p>
    </ReportingPage>
  );
};

export default Employee;
