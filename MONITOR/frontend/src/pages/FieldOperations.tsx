import React from 'react';
import {
  Chart as ChartJS,
  BarController,
  BarElement,
  CategoryScale,
  Legend,
  LinearScale,
  Tooltip,
} from 'chart.js';
import { ReportingPage, PageHeader } from '../components/reporting/PageLayout';
import Card, { CardHeader, CardBody } from '../components/reporting/Card';
import FilterBar from '../components/reporting/FilterBar';
import DataTable from '../components/reporting/DataTable';
import { DonutChart } from '../components/reporting/charts';
import { ErrorBanner, PanelState } from '../components/reporting/primitives';
// Shared with the Group Overview, which renders the same two panels — see
// OperationsPanels for why they are no longer defined here.
import { QueuePanel, TurnaroundPanel, statusTone } from '../components/reporting/OperationsPanels';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice } from '../components/reporting/DatabaseFilter';
import WidgetRange from '../components/reporting/WidgetRange';
import { Restricted, RestrictedPanel } from '../components/rbac/Restricted';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { reportingService } from '../services/reportingService';
import { OperationsData, WorkRow } from '../types/reporting';
import { WIDGET } from '../types/rbac';
import { formatDate, formatNumber } from '../utils/format';

// BarController, not only BarElement — see the note in charts.tsx for why
// omitting the controller is the trap that blanks a page rather than a panel.
ChartJS.register(BarController, CategoryScale, LinearScale, BarElement, Tooltip, Legend);

interface FieldOperationsProps {
  refreshToken: number;
}

/**
 * Operations — the field-work queues, their backlog, and how fast they clear.
 *
 * The queues differ by source: NETMANAGER has one (installations), GOWISER has
 * three (applications, job orders, service orders). The page renders whatever the
 * driver reports rather than assuming a shape, so neither source shows an empty
 * panel for work it does not model.
 */
const FieldOperations: React.FC<FieldOperationsProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { filters, update, reset, branches, databases } = useSectionFilters('operations');

  // A range per widget, on the two panels that are genuinely period-bound.
  // The queue panels below are a statement of what is open *now* and carry no
  // range at all — a backlog filtered to a date window is not a backlog.
  const slaRange = useWidgetRange('monthly');
  const concernsRange = useWidgetRange('monthly');

  const primary = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken
  );

  const sla = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken,
    { dateFrom: slaRange.range.from, dateTo: slaRange.range.to }
  );

  const concernsSection = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken,
    { dateFrom: concernsRange.range.from, dateTo: concernsRange.range.to }
  );

  const { data, loading, error, sourceLabel, substituted } = primary;

  const first = loading && !data;

  const queues = data?.queues ?? [];

  const branchLabel = data?.branch_label;
  const showBranchLabel = branchLabel && branchLabel !== 'All branches' && branchLabel !== 'All accounts';

  return (
    <ReportingPage>
      <PageHeader
        title="Operations"
        subtitle={
          <>
            Field delivery and its backlog
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

      {/* ── Queues ────────────────────────────────────────────────────── */}
      {queues.length === 0 ? (
        <Card>
          <PanelState
            loading={first}
            empty={!first}
            emptyMessage="This system records no field-work queues."
            height={180}
          >
            <span />
          </PanelState>
        </Card>
      ) : (
        <div className={`grid grid-cols-1 gap-4 ${queues.length > 1 ? 'lg:grid-cols-3' : ''}`}>
          {queues.map((queue) => (
            <QueuePanel key={queue.key} queue={queue} />
          ))}
        </div>
      )}

      {/* ── Turnaround (SLA) ──────────────────────────────────────────── */}
      <Restricted
        require={WIDGET.operationsSla}
        fallback={<RestrictedPanel title="Turnaround (SLA)" height={200} />}
      >
        <TurnaroundPanel
          turnaround={sla.data?.turnaround}
          byType={sla.data?.turnaround_by_type ?? []}
          loading={sla.loading && !sla.data}
          rangeLabel={sla.data?.range_label}
          actions={<WidgetRange state={slaRange} />}
        />
      </Restricted>

      {/* ── Why customers call, where the source records it ────────────── */}
      {(concernsSection.data?.concerns || concernsSection.data?.repair_categories) && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {concernsSection.data.concerns && (
            <Card flush>
              <CardHeader
                title="Reported Concerns"
                subtitle={concernsSection.data.range_label}
                actions={<WidgetRange state={concernsRange} />}
              />
              <CardBody>
                <PanelState
                  loading={concernsSection.loading && !concernsSection.data}
                  empty={concernsSection.data.concerns.length === 0}
                  emptyMessage="No concerns recorded in this range."
                  height={280}
                >
                  <DonutChart
                    labels={concernsSection.data.concerns.map((row) => row.label)}
                    values={concernsSection.data.concerns.map((row) => row.count)}
                    unit="count"
                    height={280}
                  />
                </PanelState>
              </CardBody>
            </Card>
          )}

          {concernsSection.data.repair_categories && (
            <Card flush>
              <CardHeader
                title="Repair Categories"
                subtitle={concernsSection.data.range_label}
                actions={<WidgetRange state={concernsRange} />}
              />
              <CardBody>
                <PanelState
                  loading={concernsSection.loading && !concernsSection.data}
                  empty={concernsSection.data.repair_categories.length === 0}
                  emptyMessage="No repairs categorised in this range."
                  height={280}
                >
                  <DonutChart
                    labels={concernsSection.data.repair_categories.map((row) => row.label)}
                    values={concernsSection.data.repair_categories.map((row) => row.count)}
                    unit="count"
                    height={280}
                  />
                </PanelState>
              </CardBody>
            </Card>
          )}
        </div>
      )}

      {/* ── Recent work ──────────────────────────────────────────────── */}
      <DataTable<WorkRow>
        title="Recent Work"
        subtitle="Most recently opened, newest first"
        rows={data?.recent ?? []}
        rowKey={(row) => row.id}
        loading={first}
        error={error}
        emptyMessage="No work has been recorded yet."
        searchPlaceholder="Search account, subscriber…"
        // Opened-desc keeps the panel's stated promise ("newest first") as the
        // starting point; every column is sortable from there.
        defaultSort="opened"
        filters={[
          {
            key: 'status',
            label: 'All statuses',
            // Built from the rows themselves rather than a hardcoded list: both
            // systems write free-text statuses and a new one must still filter.
            options: Array.from(
              new Set((data?.recent ?? []).map((row) => row.status || 'Unspecified'))
            )
              .sort()
              .map((status) => ({ value: status, label: status })),
            predicate: (row, value) => (row.status || 'Unspecified') === value,
          },
        ]}
        columns={[
          {
            key: 'status',
            header: 'Status',
            value: (row) => row.status || 'Unspecified',
            render: (row) => (
              <span
                className="inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap"
                style={{ color: statusTone(row.status) }}
              >
                <span
                  className="w-2 h-2 rounded-full flex-shrink-0"
                  style={{ backgroundColor: statusTone(row.status) }}
                />
                {row.status || 'Unspecified'}
              </span>
            ),
          },
          {
            key: 'account',
            header: 'Account #',
            value: (row) => row.account_number,
            render: (row) => (
              <span className="font-mono text-xs text-blue-600 dark:text-blue-400">
                {row.account_number || '—'}
              </span>
            ),
          },
          {
            key: 'subscriber',
            header: 'Subscriber',
            value: (row) => row.subscriber,
            render: (row) => (
              <span className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                {row.subscriber || '—'}
              </span>
            ),
          },
          {
            key: 'location',
            header: 'Location',
            value: (row) => row.location ?? '',
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {row.location || '—'}
              </span>
            ),
          },
          {
            key: 'plan',
            header: 'Plan',
            value: (row) => row.plan,
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                {row.plan || '—'}
              </span>
            ),
          },
          {
            key: 'assignee',
            header: 'Assigned to',
            value: (row) => row.assignee,
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>
                {row.assignee || <span className="italic text-gray-400">unassigned</span>}
              </span>
            ),
          },
          {
            key: 'opened',
            header: 'Opened',
            align: 'right',
            // Sorts on the raw timestamp, not the rendered "Aug 02, 2026" —
            // which would order alphabetically and put April before January.
            value: (row) => row.opened_at ?? '',
            searchable: false,
            render: (row) => (
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-500'}>
                {formatDate(row.opened_at)}
              </span>
            ),
          },
        ]}
      />

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Backlog counts every still-open job regardless of the date range — a job opened months ago is
        still waiting today.{' '}
        {data?.has_service_orders
          ? 'This system separates new connections from repairs, so they are reported as distinct queues.'
          : 'This system records field work as a single queue, so new connections and repairs are not distinguishable.'}
      </p>
    </ReportingPage>
  );
};

export default FieldOperations;
