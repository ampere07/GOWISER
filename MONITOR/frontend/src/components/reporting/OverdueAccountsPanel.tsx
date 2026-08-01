import React, { useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight, Search, X } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { formatDate, formatMoney, formatNumber, pluralise } from '../../utils/format';
import { OverdueLedger } from '../../types/reporting';
import Card from './Card';
import { SourceCell } from './DatabaseFilter';
import { Button, Pill, Table, TableState, Td, Th, Thead, Tr, useControlClass } from './primitives';

/**
 * Bucket labels depend on what the source can age by.
 *
 * NETMANAGER has a subscription end date, so overdue means days past expiry.
 * GOWISER has no expiry date — only an outstanding balance — so the same three
 * filter slots become amount bands. One control, two vocabularies, rather than
 * showing day ranges a schema cannot answer.
 */
const BUCKETS: Record<'days' | 'balance', { value: string; label: string }[]> = {
  days: [
    { value: '', label: 'All Overdue' },
    { value: '7', label: '1 – 7 days' },
    { value: '8_30', label: '8 – 30 days' },
    { value: '30', label: 'Over 30 days' },
  ],
  balance: [
    { value: '', label: 'Any Balance' },
    { value: '7', label: 'Up to ₱1,000' },
    { value: '8_30', label: '₱1,000 – ₱5,000' },
    { value: '30', label: 'Over ₱5,000' },
  ],
};

interface OverdueAccountsPanelProps {
  ledger: OverdueLedger | null;
  loading: boolean;
  error: string | null;
  onApply: (filters: { search: string; planId: number; bucket: string }) => void;
  onClear: () => void;
  onPageChange: (page: number) => void;
}

/**
 * Expired accounts, searchable and paginated.
 *
 * Filters are staged locally and only submitted on Apply. The source system does
 * the same, and it matters here: each submission is a fresh query over the whole
 * reports payload, so filtering as the user types would fire one per keystroke.
 */
const OverdueAccountsPanel: React.FC<OverdueAccountsPanelProps> = ({
  ledger,
  loading,
  error,
  onApply,
  onClear,
  onPageChange,
}) => {
  const isDarkMode = useTheme();
  const controlClass = useControlClass();

  const [search, setSearch] = useState(ledger?.filters.search ?? '');
  const [planId, setPlanId] = useState(ledger?.filters.plan_id ?? 0);
  const [bucket, setBucket] = useState(ledger?.filters.bucket ?? '');

  // Re-sync when the server reports different filters than we hold — a Clear
  // elsewhere, or a branch switch that reset them.
  useEffect(() => {
    if (!ledger) return;

    setSearch(ledger.filters.search);
    setPlanId(ledger.filters.plan_id);
    setBucket(ledger.filters.bucket);
  }, [ledger?.filters.search, ledger?.filters.plan_id, ledger?.filters.bucket]); // eslint-disable-line react-hooks/exhaustive-deps

  const apply = () => onApply({ search: search.trim(), planId, bucket });

  const clear = () => {
    setSearch('');
    setPlanId(0);
    setBucket('');
    onClear();
  };

  const rows = ledger?.rows ?? [];
  const page = ledger?.page ?? 1;
  const totalPages = ledger?.total_pages ?? 1;
  const hasFilters = Boolean(search.trim() || planId || bucket);

  // Column headings follow what the source actually measures, so the amount
  // column is never labelled MRC when it is holding a balance.
  const agesByDays = (ledger?.bucket_kind ?? 'days') === 'days';
  const buckets = BUCKETS[agesByDays ? 'days' : 'balance'];

  // Only in aggregate mode: with one database the column would repeat one value
  // on every row.
  const showSource = rows.some((row) => Boolean(row.source_label));
  const columns = showSource ? 8 : 7;

  return (
    <section className="space-y-3">
      <div>
        <h2 className={`text-xl font-bold ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
          {agesByDays ? 'Overdue / Expired Accounts' : 'Accounts in Arrears'}
        </h2>
        <p className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
          {ledger ? pluralise(ledger.total, 'account') + ' found' : 'Loading…'}
        </p>
      </div>

      <Card>
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 min-w-[200px]">
            <Search
              size={14}
              className={`absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none ${
                isDarkMode ? 'text-gray-500' : 'text-gray-400'
              }`}
            />
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') apply();
              }}
              placeholder="Name, account, contact…"
              aria-label="Search overdue accounts"
              className={`${controlClass} w-full pl-9`}
            />
          </div>

          <select
            value={planId}
            onChange={(event) => setPlanId(Number(event.target.value))}
            aria-label="Filter by plan"
            className={`${controlClass} min-w-[160px]`}
          >
            <option value={0}>All Plans</option>
            {(ledger?.plans ?? []).map((plan) => (
              <option key={plan.id} value={plan.id}>
                {plan.label}
              </option>
            ))}
          </select>

          <select
            value={bucket}
            onChange={(event) => setBucket(event.target.value)}
            aria-label={agesByDays ? 'Filter by how far overdue' : 'Filter by balance owed'}
            className={`${controlClass} min-w-[160px]`}
          >
            {buckets.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>

          <Button variant="primary" icon={<Search size={14} />} onClick={apply} title="Apply filters" />
          <Button
            variant="outline"
            icon={<X size={14} />}
            onClick={clear}
            disabled={!hasFilters}
            title="Clear filters"
          />
        </div>
      </Card>

      <Card flush>
        <Table>
          <Thead>
            {showSource && <Th>Database</Th>}
            <Th>Account #</Th>
            <Th>Subscriber</Th>
            <Th>Contact</Th>
            <Th>Plan</Th>
            <Th align="right">{agesByDays ? 'MRC' : 'Balance'}</Th>
            <Th align="right">{agesByDays ? 'Expired On' : 'Last Updated'}</Th>
            <Th align="right">{agesByDays ? 'Days Overdue' : 'Status'}</Th>
          </Thead>
          <tbody>
            <TableState
              colSpan={columns}
              loading={loading && rows.length === 0}
              error={error}
              empty={rows.length === 0}
              emptyMessage={
                hasFilters
                  ? 'No accounts match these filters.'
                  : agesByDays
                  ? 'No accounts are overdue. Nothing to chase.'
                  : 'No accounts carry a balance. Nothing to chase.'
              }
            />

            {rows.map((row) => (
              <Tr key={row.id}>
                {showSource && (
                  <Td>
                    <SourceCell label={row.source_label} />
                  </Td>
                )}
                <Td className="font-mono text-xs text-blue-600 dark:text-blue-400">
                  {row.account_number || '—'}
                </Td>
                <Td className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                  {row.subscriber || '—'}
                </Td>
                <Td className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>
                  {row.contact_number || '—'}
                </Td>
                <Td className={isDarkMode ? 'text-gray-300' : 'text-gray-700'}>{row.plan || '—'}</Td>
                <Td align="right" className={isDarkMode ? 'text-gray-200' : 'text-gray-900'}>
                  {formatMoney(row.mrc)}
                </Td>
                <Td align="right" className="text-red-500 dark:text-red-400">
                  {formatDate(row.expired_on)}
                </Td>
                <Td align="right">
                  {row.days_overdue !== null && row.days_overdue !== undefined ? (
                    <Pill tone="danger">{formatNumber(row.days_overdue)}d</Pill>
                  ) : row.status ? (
                    <Pill tone="warning">{row.status}</Pill>
                  ) : (
                    <span className={isDarkMode ? 'text-gray-500' : 'text-gray-400'}>—</span>
                  )}
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>

        {totalPages > 1 && (
          <div
            className={`flex items-center justify-between gap-3 px-4 py-3 border-t ${
              isDarkMode ? 'border-gray-800' : 'border-gray-200'
            }`}
          >
            <span className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
              Page {page} of {totalPages}
            </span>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                icon={<ChevronLeft size={14} />}
                onClick={() => onPageChange(page - 1)}
                disabled={page <= 1 || loading}
                title="Previous page"
              />
              <Button
                variant="outline"
                icon={<ChevronRight size={14} />}
                onClick={() => onPageChange(page + 1)}
                disabled={page >= totalPages || loading}
                title="Next page"
              />
            </div>
          </div>
        )}
      </Card>
    </section>
  );
};

export default OverdueAccountsPanel;
