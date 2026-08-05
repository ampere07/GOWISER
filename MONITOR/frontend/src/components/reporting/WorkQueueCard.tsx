import React from 'react';
import { CadenceWindow, QueueCadence } from '../../types/reporting';
import { useTheme } from '../../hooks/useTheme';
import { formatNumber } from '../../utils/format';
import Card, { CardHeader, CardBody } from './Card';
import { PanelState } from './primitives';

/**
 * One work queue, in plain English, on three fixed windows.
 *
 * This replaced a card whose figures followed a date picker. The picker was the
 * problem: "Installed" meant whatever range happened to be selected, two people
 * reading the same screen were often reading different windows, and the number
 * could not be quoted without also quoting the filter. Today, This Week and This
 * Month are shown together and each is labelled, so a figure read off this card
 * carries its own period with it.
 *
 * Rows are driven by props rather than baked in, because the three queues want
 * the same layout with different vocabularies — an application is "Scheduled for
 * Setup", a job order is "To Be Installed", and calling both "In Progress" is
 * what the relabelling was asked to fix.
 */

export interface QueueRow {
  /** The bucket key in the cadence payload. */
  key: string;
  label: string;
  tone?: 'success' | 'danger' | 'warning' | 'info' | 'neutral';
  /** Renders as a sub-item of the heading above it — see Pending Action. */
  indent?: boolean;
}

export interface QueueSection {
  /** A row group with no figures of its own, e.g. "Pending Action". */
  heading?: string;
  rows: QueueRow[];
}

interface WorkQueueCardProps {
  title: string;
  subtitle?: React.ReactNode;
  icon?: React.ReactNode;
  loading: boolean;
  error: string | null;
  /** False when no monitored schema models this queue. */
  available: boolean;
  cadence?: QueueCadence;
  sections: QueueSection[];
  /** The row whose figures head the card, usually the total. */
  headline?: { label: string; key: string };
}

const WINDOWS: { key: CadenceWindow; label: string; short: string }[] = [
  { key: 'today', label: 'Today', short: 'Today' },
  { key: 'week', label: 'This Week', short: 'Week' },
  { key: 'month', label: 'This Month', short: 'Month' },
];

const TONE_TEXT: Record<NonNullable<QueueRow['tone']>, string> = {
  success: 'text-emerald-600 dark:text-emerald-400',
  danger: 'text-red-500 dark:text-red-400',
  warning: 'text-amber-500 dark:text-amber-400',
  info: 'text-blue-600 dark:text-blue-400',
  neutral: '',
};

const WorkQueueCard: React.FC<WorkQueueCardProps> = ({
  title,
  subtitle,
  icon,
  loading,
  error,
  available,
  cadence,
  sections,
  headline,
}) => {
  const isDarkMode = useTheme();

  const valueFor = (windowKey: CadenceWindow, bucket: string): number | undefined =>
    cadence?.[windowKey]?.[bucket];

  return (
    <Card flush>
      <CardHeader title={title} subtitle={subtitle} icon={icon} />
      <CardBody>
        <PanelState
          loading={loading && !cadence}
          error={error}
          empty={!available}
          emptyMessage="No monitored system records this queue."
          height={210}
        >
          <div>
            {headline && (
              <div className="flex items-baseline justify-between gap-3 mb-3">
                <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                  {headline.label}
                </p>
                <div className="flex items-baseline gap-4">
                  {WINDOWS.map((w) => (
                    <span
                      key={w.key}
                      className={`text-xl font-bold tabular-nums ${
                        isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}
                      title={w.label}
                    >
                      {formatNumber(valueFor(w.key, headline.key))}
                    </span>
                  ))}
                </div>
              </div>
            )}

            {/* A plain table rather than a grid of tiles: three windows across
                four labels is a matrix, and a reader compares down a column.
                Tiles would put the same comparison on two axes at once. */}
            <table className="w-full text-sm">
              <thead>
                <tr>
                  <th />
                  {WINDOWS.map((w) => (
                    <th
                      key={w.key}
                      title={w.label}
                      className={`px-1 pb-2 text-right text-[11px] font-semibold uppercase tracking-wide ${
                        isDarkMode ? 'text-gray-400' : 'text-gray-500'
                      }`}
                    >
                      {w.short}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {sections.map((section, index) => (
                  <React.Fragment key={section.heading ?? `section-${index}`}>
                    {section.heading && (
                      <tr>
                        <td
                          colSpan={WINDOWS.length + 1}
                          className={`pt-3 pb-1 text-[11px] font-semibold uppercase tracking-wide ${
                            isDarkMode ? 'text-gray-500' : 'text-gray-400'
                          }`}
                        >
                          {section.heading}
                        </td>
                      </tr>
                    )}

                    {section.rows.map((row) => (
                      <tr
                        key={row.key}
                        className={`border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-100'}`}
                      >
                        <td
                          className={`py-2 pr-2 ${row.indent ? 'pl-3' : ''} ${
                            isDarkMode ? 'text-gray-300' : 'text-gray-700'
                          }`}
                        >
                          {row.label}
                        </td>
                        {WINDOWS.map((w) => (
                          <td
                            key={w.key}
                            className={`py-2 px-1 text-right font-semibold tabular-nums ${
                              TONE_TEXT[row.tone ?? 'neutral'] ||
                              (isDarkMode ? 'text-gray-100' : 'text-gray-900')
                            }`}
                          >
                            {formatNumber(valueFor(w.key, row.key))}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </React.Fragment>
                ))}

                {/* The residue: statuses no bucket claimed. Shown only when it
                    is non-zero, because its whole purpose is to be noticed — a
                    permanent "Other: 0" row trains people to skip it, and the
                    month it turns into 40 they still skip it. */}
                {(valueFor('month', 'other') ?? 0) > 0 && (
                  <tr className={`border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-100'}`}>
                    <td
                      className={`py-2 pr-2 italic ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}
                      title="Statuses that match none of the categories above. A new workflow state in the source app shows up here."
                    >
                      Other status
                    </td>
                    {WINDOWS.map((w) => (
                      <td
                        key={w.key}
                        className={`py-2 px-1 text-right tabular-nums ${
                          isDarkMode ? 'text-gray-500' : 'text-gray-400'
                        }`}
                      >
                        {formatNumber(valueFor(w.key, 'other'))}
                      </td>
                    ))}
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </PanelState>
      </CardBody>
    </Card>
  );
};

export default WorkQueueCard;
