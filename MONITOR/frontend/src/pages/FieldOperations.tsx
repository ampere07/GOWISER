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
import { DonutChart } from '../components/reporting/charts';
import { ErrorBanner, PanelState } from '../components/reporting/primitives';
// Shared with the Group Overview, which renders the same two panels — see
// OperationsPanels for why they are no longer defined here.
import { QueuePanel, TurnaroundPanel } from '../components/reporting/OperationsPanels';
import { SourceNotice, useSectionFilters } from '../components/reporting/sectionShell';
import { AggregateNotice } from '../components/reporting/DatabaseFilter';
import { PageActions, PagePeriodBar, usePageChrome } from '../components/reporting/PageChrome';
import WidgetRange from '../components/reporting/WidgetRange';
import { Restricted, RestrictedPanel } from '../components/rbac/Restricted';
import { useReportingSection } from '../hooks/useReportingSection';
import { useTheme } from '../hooks/useTheme';
import { useWidgetRange } from '../hooks/useWidgetRange';
import { useLinkedRange } from '../hooks/useLinkedRange';
import { reportingService } from '../services/reportingService';
import { OperationsData } from '../types/reporting';
import { WIDGET } from '../types/rbac';

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

  const chrome = usePageChrome();
  const [reloads, setReloads] = React.useState(0);

  // The page period drives the two panels that are genuinely period-bound.
  // The queue panels are a statement of what the queue holds *now* and carry no
  // range at all — a queue does not have a date, which is why the pipeline is
  // counted all-time on the backend rather than over this window.
  const pageRange = useWidgetRange('monthly');
  const slaRange = useLinkedRange(pageRange);
  const concernsRange = useLinkedRange(pageRange);

  const primary = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken + reloads
  );

  const sla = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken + reloads,
    { dateFrom: slaRange.range.from, dateTo: slaRange.range.to }
  );

  const concernsSection = useReportingSection<OperationsData>(
    reportingService.getOperations,
    filters,
    refreshToken + reloads,
    { dateFrom: concernsRange.range.from, dateTo: concernsRange.range.to }
  );

  const { data, loading, error, source, sourceLabel, substituted } = primary;

  const refresh = () => {
    reportingService.invalidate(source || undefined);
    setReloads((count) => count + 1);
  };

  const first = loading && !data;

  const queues = data?.queues ?? [];

  const branchLabel = data?.branch_label;
  const showBranchLabel = branchLabel && branchLabel !== 'All branches' && branchLabel !== 'All accounts';

  return (
    <div ref={chrome.container} className={chrome.containerClass}>
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
        actions={<PageActions chrome={chrome} onRefresh={refresh} refreshing={loading} />}
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

      <PagePeriodBar
        chrome={chrome}
        state={pageRange}
        // The queues below ignore it, and say so here rather than leaving the
        // reader to work out why three of the five panels never move.
        trailing={
          <span className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            turnaround &amp; concerns · queues are the whole backlog
          </span>
        }
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
          {/* `plainLabels` matches the Group Overview, which has always
              rendered these three panels under the business's own names. It was
              off here on the argument that a field manager works in the source
              system's vocabulary and renaming it under them makes this page
              harder to reconcile against the system they administer.

              That held while the two screens were read by different people. They
              are not: the same panel appearing as "Job Orders / Done" on one tab
              and "New Installations / Installed" on the next is the same report
              making two different claims, and reconciling *those* is the harder
              job. The status names below are unmapped fall-throughs anyway, so
              anything the operating system adds still shows under its own name.
          */}
          {queues.map((queue) => (
            <QueuePanel key={queue.key} queue={queue} plainLabels />
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

      {/* A "Recent Work" table stood here and has been removed.

          It listed the last few dozen rows across all three queues, sorted by
          when they were opened — which is neither a backlog (the queue panels
          above are, and they count every still-open job regardless of age) nor a
          report (it was capped at whatever the driver returned, with no paging
          and no total). What it actually answered was "what happened lately",
          which nobody manages a field team by, and its search box invited using
          it as a lookup tool against a list that did not contain most records.

          `recent` is still in the API payload and still computed by the drivers.
          It is the panel that had no question, not the data. */}

      <p className={`text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
        Backlog counts every still-open job regardless of the date range — a job opened months ago is
        still waiting today.{' '}
        {data?.has_service_orders
          ? 'This system separates new connections from repairs, so they are reported as distinct queues.'
          : 'This system records field work as a single queue, so new connections and repairs are not distinguishable.'}
      </p>
    </ReportingPage>
    </div>
  );
};

export default FieldOperations;
