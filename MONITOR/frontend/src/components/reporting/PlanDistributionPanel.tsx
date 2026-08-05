import React, { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, PieChart as PieIcon } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { formatMoney, formatNumber, formatPercent, pluralise } from '../../utils/format';
import { PlanShare, PlanSort } from '../../types/reporting';
import Card, { CardHeader, CardBody } from './Card';
import { PieChart, SLICE_COLORS } from './charts';
import { Dot, PanelState, Table, Td, Th, Thead, TotalRow, Tr } from './primitives';

interface PlanDistributionPanelProps {
  rows: PlanShare[];
  loading: boolean;
  error?: string | null;
  /** Rendered in the header — the widget's own date-range control, if any. */
  actions?: React.ReactNode;
}

/**
 * How the subscriber base splits across plans: a pie on the left, its numbers on
 * the right.
 *
 * Two representations of one dataset rather than a choice between them, because
 * they answer different halves of the same question. The pie shows shape — which
 * plan dominates, whether the tail is long — at a glance and from across a room.
 * The table shows the figures, sorted however the reader wants them, which is
 * what anyone actually quotes.
 *
 * The pie is capped and the table is not. Above a dozen slices a pie stops being
 * readable — the wedges are thinner than their own leader lines — so the tail is
 * gathered into one "Other plans" slice that the table then breaks out in full.
 * Capping both would hide the tail entirely; capping neither produces a chart
 * that cannot be read.
 *
 * Shares come from the server, never recomputed here: the aggregate path merges
 * several databases and recomputes them against the fleet total, and a second
 * formula in React would eventually disagree with it.
 */
const PIE_SLICES = 11;

const PlanDistributionPanel: React.FC<PlanDistributionPanelProps> = ({
  rows,
  loading,
  error,
  actions,
}) => {
  const isDarkMode = useTheme();

  const [sort, setSort] = useState<PlanSort>('count');
  const [descending, setDescending] = useState(true);

  const sorted = useMemo(() => {
    // Copied before sorting: the same array feeds the chart, and sorting in
    // place would reorder its slices on every header click.
    return [...rows].sort((a, b) => {
      if (sort === 'label') {
        const compared = a.label.localeCompare(b.label);
        return descending ? -compared : compared;
      }

      const compared = a[sort] - b[sort];

      // Ties broken by name so the order is stable between polls rather than
      // reshuffling equal rows every refresh.
      return compared !== 0
        ? descending
          ? -compared
          : compared
        : a.label.localeCompare(b.label);
    });
  }, [rows, sort, descending]);

  // Largest first for the chart regardless of how the table is sorted: the pie
  // reads clockwise from the biggest wedge, which is not a preference the
  // reader is expressing when they sort the table by name.
  //
  // The rank map is built in the same pass and keyed by label, so the table's
  // colour dots are a lookup rather than a search per row — the tail can run to
  // dozens of plans and re-ranking inside the render loop is quadratic.
  const { slices, rankByLabel } = useMemo(() => {
    const ranked = [...rows].sort((a, b) => b.count - a.count);
    const ranks = new Map<string, number>();

    ranked.forEach((row, index) => ranks.set(row.label, index));

    if (ranked.length <= PIE_SLICES) {
      return {
        slices: ranked.map((row) => ({ label: row.label, count: row.count })),
        rankByLabel: ranks,
      };
    }

    const head = ranked.slice(0, PIE_SLICES - 1);
    const tail = ranked.slice(PIE_SLICES - 1);

    return {
      slices: [
        ...head.map((row) => ({ label: row.label, count: row.count })),
        {
          label: `Other plans (${tail.length})`,
          count: tail.reduce((sum, row) => sum + row.count, 0),
        },
      ],
      rankByLabel: ranks,
    };
  }, [rows]);

  const total = rows.reduce((sum, row) => sum + row.count, 0);

  const applySort = (key: PlanSort) => {
    if (key === sort) {
      setDescending((current) => !current);
      return;
    }

    setSort(key);
    // A new numeric column opens largest-first, a name column A–Z: both are the
    // direction someone wants on the first click.
    setDescending(key !== 'label');
  };

  const SortableTh: React.FC<{ column: PlanSort; label: string; align?: 'left' | 'right' }> = ({
    column,
    label,
    align = 'right',
  }) => (
    <Th align={align}>
      <button
        type="button"
        onClick={() => applySort(column)}
        className="inline-flex items-center gap-1 hover:opacity-70 transition-opacity"
        title={`Sort by ${label}`}
      >
        {label}
        {sort === column && (descending ? <ArrowDown size={12} /> : <ArrowUp size={12} />)}
      </button>
    </Th>
  );

  return (
    <Card flush>
      <CardHeader
        title="Plan Distribution"
        subtitle={`${pluralise(rows.length, 'plan')} across ${formatNumber(total)} subscribers`}
        icon={<PieIcon size={16} />}
        actions={actions}
      />
      <CardBody>
        <PanelState
          loading={loading && rows.length === 0}
          error={error ?? null}
          empty={rows.length === 0}
          emptyMessage="No subscribers are assigned to a plan."
          height={280}
        >
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div className="min-w-0">
              <PieChart
                labels={slices.map((slice) => slice.label)}
                values={slices.map((slice) => slice.count)}
                unit="count"
                height={300}
              />
            </div>

            <div className="min-w-0 overflow-x-auto">
              <Table>
                <Thead>
                  <Th width="14px" />
                  <SortableTh column="label" label="Plan Name" align="left" />
                  <SortableTh column="share_pct" label="Distribution" />
                  <SortableTh column="count" label="Subscribers" />
                </Thead>
                <tbody>
                  {sorted.map((row) => {
                    // The dot ties a row to its wedge, so it follows the chart's
                    // ranking rather than the table's current sort. Rows past the
                    // cap share the "Other plans" colour, because that is
                    // genuinely the wedge they are inside.
                    const rank = rankByLabel.get(row.label) ?? PIE_SLICES - 1;

                    return (
                      <Tr key={`${row.plan_id}-${row.label}`}>
                        <Td>
                          <Dot
                            color={
                              rank < PIE_SLICES - 1
                                ? SLICE_COLORS[rank % SLICE_COLORS.length]
                                : SLICE_COLORS[(PIE_SLICES - 1) % SLICE_COLORS.length]
                            }
                          />
                        </Td>
                        <Td className={`font-medium ${isDarkMode ? 'text-gray-100' : 'text-gray-900'}`}>
                          {row.label}
                          {row.price > 0 && (
                            <span className={`ml-1.5 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                              {formatMoney(row.price)}
                            </span>
                          )}
                          {/* The legacy strings behind the unmapped bucket. Named
                              on the row itself so fixing them is a lookup rather
                              than a database trawl. */}
                          {(row.samples?.length ?? 0) > 0 && (
                            <span
                              className={`block text-[11px] mt-0.5 ${
                                isDarkMode ? 'text-amber-400/80' : 'text-amber-700'
                              }`}
                            >
                              e.g. {row.samples?.slice(0, 4).map((s) => `"${s}"`).join(', ')}
                              {(row.samples?.length ?? 0) > 4 ? ' …' : ''}
                            </span>
                          )}
                        </Td>
                        <Td align="right" className="tabular-nums">
                          {formatPercent(row.share_pct)}
                        </Td>
                        <Td
                          align="right"
                          className={`font-semibold tabular-nums ${
                            isDarkMode ? 'text-white' : 'text-gray-900'
                          }`}
                        >
                          {formatNumber(row.count)}
                        </Td>
                      </Tr>
                    );
                  })}

                  <TotalRow>
                    <Td />
                    <Td>Total</Td>
                    <Td align="right" className="tabular-nums">
                      {/* Stated rather than assumed: rounding each share to one
                          decimal can leave the column reading 99.9 or 100.1, and
                          a reader is entitled to see which. */}
                      {formatPercent(sorted.reduce((sum, row) => sum + row.share_pct, 0))}
                    </Td>
                    <Td align="right" className="tabular-nums">
                      {formatNumber(total)}
                    </Td>
                  </TotalRow>
                </tbody>
              </Table>
            </div>
          </div>
        </PanelState>
      </CardBody>
    </Card>
  );
};

export default PlanDistributionPanel;
