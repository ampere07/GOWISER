import React from 'react';
import { AlarmClock, Gift, Timer } from 'lucide-react';
import { CadenceWindow, FreeConnections, ResolutionSummary } from '../../types/reporting';
import { useTheme } from '../../hooks/useTheme';
import { formatNumber } from '../../utils/format';
import Card, { CardHeader, CardBody } from './Card';
import { PanelState } from './primitives';

/**
 * Two questions an executive asks about service delivery that no count answers:
 * how fast is work being closed, and who has been waiting longest.
 *
 * They sit together because they are the two halves of the same judgement. A
 * healthy "Completed & Closed" figure beside a ticket that has been open for
 * eleven weeks is the case worth seeing — the throughput number alone reads as
 * success, and on its own it hides the customer who has given up ringing.
 */

const WINDOWS: { key: CadenceWindow; label: string }[] = [
  { key: 'today', label: 'Today' },
  { key: 'week', label: 'This Week' },
  { key: 'month', label: 'This Month' },
];

/**
 * A duration a non-technical reader can act on.
 *
 * Days and hours rather than hours alone: "412 hours" is a number people have to
 * do arithmetic on before they can feel it, and the point of this figure is that
 * it should be felt.
 */
export const formatDuration = (days: number, hours: number): string => {
  if (hours <= 0) return 'under an hour';

  const remainder = hours % 24;

  if (days <= 0) return `${hours} hr${hours === 1 ? '' : 's'}`;
  if (remainder === 0) return `${days} day${days === 1 ? '' : 's'}`;

  return `${days} day${days === 1 ? '' : 's'} ${remainder} hr${remainder === 1 ? '' : 's'}`;
};

interface ResolutionCardProps {
  resolution?: ResolutionSummary;
  loading: boolean;
  error: string | null;
}

export const ResolutionSlaCard: React.FC<ResolutionCardProps> = ({ resolution, loading, error }) => {
  const isDarkMode = useTheme();
  const longest = resolution?.longest_outstanding ?? null;

  return (
    <Card flush>
      <CardHeader
        title="Resolution Speed"
        subtitle="Work finished, and the ticket still waiting longest"
        icon={<Timer size={16} />}
      />
      <CardBody>
        <PanelState
          loading={loading && !resolution}
          error={error}
          empty={resolution?.available === false}
          emptyMessage="No monitored system records installation or repair queues."
          height={200}
        >
          <div className="space-y-4">
            <div>
              <p
                className={`text-[11px] font-semibold uppercase tracking-wide mb-2 ${
                  isDarkMode ? 'text-gray-500' : 'text-gray-400'
                }`}
              >
                Completed &amp; Closed
              </p>

              <div className="grid grid-cols-3 gap-2">
                {WINDOWS.map((w) => {
                  const cell = resolution?.completed?.[w.key];

                  return (
                    <div
                      key={w.key}
                      className={`rounded-lg px-2.5 py-2 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}
                    >
                      <p className={`text-[11px] ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                        {w.label}
                      </p>
                      <p
                        className={`text-lg font-bold tabular-nums ${
                          isDarkMode ? 'text-white' : 'text-gray-900'
                        }`}
                      >
                        {formatNumber(cell?.total)}
                      </p>
                      {/* The split is the useful part: "we closed 40" hides
                          whether the field force spent the week connecting new
                          customers or repairing existing ones. */}
                      <p className={`text-[10px] mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        {formatNumber(cell?.installations)} installed ·{' '}
                        {formatNumber(cell?.repairs)} repaired
                      </p>
                    </div>
                  );
                })}
              </div>
            </div>

            <div>
              <p
                className={`text-[11px] font-semibold uppercase tracking-wide mb-2 ${
                  isDarkMode ? 'text-gray-500' : 'text-gray-400'
                }`}
              >
                Longest Outstanding Ticket
              </p>

              {longest === null ? (
                <div
                  className={`rounded-lg px-3 py-3 text-sm ${
                    isDarkMode ? 'bg-gray-800/60 text-gray-400' : 'bg-gray-50 text-gray-500'
                  }`}
                >
                  Nothing outstanding — every installation and repair is closed.
                </div>
              ) : (
                <div
                  className={`rounded-lg px-3 py-3 border ${
                    isDarkMode
                      ? 'bg-red-500/10 border-red-500/30'
                      : 'bg-red-50 border-red-200'
                  }`}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p
                        className={`font-semibold truncate ${
                          isDarkMode ? 'text-gray-100' : 'text-gray-900'
                        }`}
                      >
                        {longest.customer || 'Unnamed customer'}
                      </p>
                      <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
                        Account {longest.account_no || '—'} · {longest.label} · {longest.status}
                      </p>
                      <p className={`text-[11px] mt-1 ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
                        Open since {longest.opened_at}
                        {longest.source_label ? ` · ${longest.source_label}` : ''}
                      </p>
                    </div>

                    <div className="flex-shrink-0 text-right">
                      <AlarmClock
                        size={14}
                        className={`inline-block mb-1 ${
                          isDarkMode ? 'text-red-400' : 'text-red-500'
                        }`}
                      />
                      <p
                        className={`text-sm font-bold tabular-nums ${
                          isDarkMode ? 'text-red-400' : 'text-red-600'
                        }`}
                      >
                        {formatDuration(longest.days, longest.hours)}
                      </p>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        </PanelState>
      </CardBody>
    </Card>
  );
};

interface FreeConnectionsCardProps {
  free?: FreeConnections;
  loading: boolean;
  error: string | null;
}

/**
 * Subscribers who are connected but not billed.
 *
 * Reported as two counts rather than one, because an account can be exempted in
 * two different places — a VIP billing status, or a plan named for it — and
 * finance asks about both. The headline is the larger of the two, not their sum:
 * they overlap, the overlap is not measurable from these aggregates, and summing
 * would overstate the giveaway. The note under the figure says so on screen, so
 * nobody has to guess whether it was added or maxed.
 */
export const FreeConnectionsCard: React.FC<FreeConnectionsCardProps> = ({
  free,
  loading,
  error,
}) => {
  const isDarkMode = useTheme();

  return (
    <Card flush>
      <CardHeader
        title="Free Connection Subscribers"
        subtitle="Connected, not billed"
        icon={<Gift size={16} />}
      />
      <CardBody>
        <PanelState
          loading={loading && !free}
          error={error}
          empty={!free}
          emptyMessage="No subscriber data available."
          height={200}
        >
          <div>
            <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              Total free connections
            </p>
            <p
              className={`text-3xl font-bold tracking-tight tabular-nums ${
                isDarkMode ? 'text-white' : 'text-gray-900'
              }`}
            >
              {formatNumber(free?.total)}
            </p>
            {free?.overlaps && (
              <p className={`text-[11px] mt-0.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                the larger of the two counts below — they overlap, so they are not added
              </p>
            )}

            <div className="grid grid-cols-2 gap-2 mt-4">
              <div className={`rounded-lg px-2.5 py-2 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}>
                <p className={`text-[11px] ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                  VIP billing status
                </p>
                <p
                  className={`text-base font-semibold tabular-nums ${
                    isDarkMode ? 'text-white' : 'text-gray-900'
                  }`}
                >
                  {formatNumber(free?.vip_status_accounts)}
                </p>
              </div>
              <div className={`rounded-lg px-2.5 py-2 ${isDarkMode ? 'bg-gray-800/60' : 'bg-gray-50'}`}>
                <p className={`text-[11px] ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                  On a free plan
                </p>
                <p
                  className={`text-base font-semibold tabular-nums ${
                    isDarkMode ? 'text-white' : 'text-gray-900'
                  }`}
                >
                  {formatNumber(free?.free_plan_accounts)}
                </p>
              </div>
            </div>

            {(free?.plans?.length ?? 0) > 0 && (
              <div className="mt-3 space-y-1.5">
                <p
                  className={`text-[11px] font-semibold uppercase tracking-wide ${
                    isDarkMode ? 'text-gray-500' : 'text-gray-400'
                  }`}
                >
                  Free plans in use
                </p>
                {free?.plans?.map((plan) => (
                  <div
                    key={plan.label}
                    className="flex items-center justify-between gap-3 text-sm"
                  >
                    <span className={`truncate ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                      {plan.label}
                    </span>
                    <span
                      className={`font-semibold tabular-nums flex-shrink-0 ${
                        isDarkMode ? 'text-gray-100' : 'text-gray-900'
                      }`}
                    >
                      {formatNumber(plan.count)}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </PanelState>
      </CardBody>
    </Card>
  );
};
