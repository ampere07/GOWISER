import React from 'react';
import { MapPin } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { formatMoney, formatPercent } from '../../utils/format';
import { BranchCollectionRow, Granularity } from '../../types/reporting';
import Card, { CardHeader } from './Card';
import PeriodTabs from './PeriodTabs';
import { PieChart, SLICE_COLORS } from './charts';
import { Bar, Dot, PanelState, Table, TableState, Td, Th, Thead, TotalRow, Tr, useControlClass } from './primitives';

interface RouterReportsPanelProps {
  rows: BranchCollectionRow[];
  label: string;
  period: Granularity;
  onPeriodChange: (period: Granularity) => void;
  year: number;
  years: number[];
  onYearChange: (year: number) => void;
  loading: boolean;
  error: string | null;
}

/**
 * Collections per router for the selected period, as a table beside a pie.
 *
 * The two halves share one colour assignment — index into SLICE_COLORS — so the
 * dot next to a router's name is the same colour as its slice. Without that the
 * pie is decorative rather than readable.
 */
const RouterReportsPanel: React.FC<RouterReportsPanelProps> = ({
  rows,
  label,
  period,
  onPeriodChange,
  year,
  years,
  onYearChange,
  loading,
  error,
}) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const total = rows.reduce((sum, row) => sum + row.collection, 0);

  // A pie of nothing but zeroes renders as one arbitrary full circle, which
  // reads as "one router collected everything". Better to show the empty state.
  const hasCollections = total > 0;

  return (
    <Card flush>
      <CardHeader
        title="Collections by Branch"
        badge={label}
        actions={
          <>
            {period === 'yearly' && years.length > 0 && (
              <select
                value={year}
                onChange={(event) => onYearChange(Number(event.target.value))}
                className={controlClass}
                aria-label="Report year"
              >
                {years.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            )}
            <PeriodTabs value={period} onChange={onPeriodChange} size="sm" />
          </>
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-5">
        <div className="lg:col-span-3">
          <Table>
            <Thead>
              <Th>Router</Th>
              <Th align="right">Collection</Th>
              <Th align="right" width="120px">
                Share
              </Th>
            </Thead>
            <tbody>
              <TableState
                colSpan={3}
                loading={loading && rows.length === 0}
                error={error}
                empty={rows.length === 0}
                emptyMessage="No routers are registered in this system."
              />

              {rows.map((row, index) => (
                <Tr key={row.id}>
                  <Td>
                    <div className="flex items-start gap-2">
                      <span className="mt-1.5">
                        <Dot color={SLICE_COLORS[index % SLICE_COLORS.length]} />
                      </span>
                      <div className="min-w-0">
                        <p className={`font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                          {row.label}
                        </p>
                        {row.location && (
                          <p
                            className={`flex items-center gap-1 text-xs mt-0.5 ${
                              isDarkMode ? 'text-gray-400' : 'text-gray-500'
                            }`}
                          >
                            <MapPin size={11} className="flex-shrink-0" />
                            <span className="truncate">{row.location}</span>
                          </p>
                        )}
                      </div>
                    </div>
                  </Td>
                  <Td align="right" className="font-semibold text-emerald-600 dark:text-emerald-400">
                    {formatMoney(row.collection)}
                  </Td>
                  <Td align="right">
                    <span className="inline-flex items-center gap-2 whitespace-nowrap">
                      <Bar
                        pct={row.share_pct}
                        color={SLICE_COLORS[index % SLICE_COLORS.length]}
                        width={28}
                      />
                      <span className={isDarkMode ? 'text-gray-300' : 'text-gray-600'}>
                        {formatPercent(row.share_pct)}
                      </span>
                    </span>
                  </Td>
                </Tr>
              ))}

              {rows.length > 0 && (
                <TotalRow>
                  <Td>Total</Td>
                  <Td align="right" className="text-emerald-600 dark:text-emerald-400">
                    {formatMoney(total)}
                  </Td>
                  <Td />
                </TotalRow>
              )}
            </tbody>
          </Table>
        </div>

        <div
          className={`lg:col-span-2 p-4 border-t lg:border-t-0 lg:border-l ${
            isDarkMode ? 'border-gray-800' : 'border-gray-200'
          }`}
        >
          <p
            className={`text-sm font-semibold text-center mb-2 ${
              isDarkMode ? 'text-gray-300' : 'text-gray-600'
            }`}
          >
            Collection by Router
          </p>

          <PanelState
            loading={loading && rows.length === 0}
            empty={!hasCollections}
            emptyMessage="No collections in this period."
            height={280}
          >
            <PieChart
              labels={rows.map((row) => row.label)}
              values={rows.map((row) => row.collection)}
              unit="money"
              height={280}
            />
          </PanelState>
        </div>
      </div>
    </Card>
  );
};

export default RouterReportsPanel;
