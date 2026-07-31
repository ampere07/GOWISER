import React, { useEffect, useState } from 'react';
import { AlertTriangle, Banknote, FileText, Users, Wifi } from 'lucide-react';
import PageShell from '../components/common/PageShell';
import MetricCard, { formatCurrency } from '../components/common/MetricCard';
import { monitorService } from '../services/monitorService';
import { useTheme } from '../hooks/useTheme';
import { Consolidated as ConsolidatedData } from '../types/monitor';

interface ConsolidatedProps {
  refreshToken: number;
}

/**
 * Every monitored database side by side. This is the view that only exists
 * because MONITOR spans databases — it ignores the source switcher entirely.
 */
const Consolidated: React.FC<ConsolidatedProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const [data, setData] = useState<ConsolidatedData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);

    monitorService
      .getConsolidated()
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;
        console.error('Consolidated fetch failed:', err);
        setError(err?.response?.data?.message || 'Unable to load the consolidated view.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [refreshToken]);

  const unreachable = data?.sources.filter((row) => !row.reachable) ?? [];

  return (
    <PageShell
      title="All Companies"
      subtitle="Every monitored database, month to date"
      error={error}
    >
      {unreachable.length > 0 && (
        <div className="p-3 rounded-xl bg-orange-500/10 border border-orange-500/20 text-orange-500 flex items-start gap-3">
          <AlertTriangle className="h-4 w-4 flex-shrink-0 mt-0.5" />
          <div className="text-sm">
            <p className="font-medium">
              {unreachable.length} source{unreachable.length > 1 ? 's are' : ' is'} unreachable — group
              totals below exclude {unreachable.length > 1 ? 'them' : 'it'}.
            </p>
            <p className="text-xs mt-1 opacity-80">
              {unreachable.map((row) => row.label).join(', ')}
            </p>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
        <MetricCard
          title="Group Collected MTD"
          value={data?.totals.revenue_mtd}
          currency
          icon={<Banknote size={20} />}
          iconColor="text-emerald-500"
          loading={loading}
        />
        <MetricCard
          title="Group Receivables"
          value={data?.totals.receivables}
          currency
          icon={<FileText size={20} />}
          iconColor="text-orange-500"
          loading={loading}
        />
        <MetricCard
          title="Group Accounts"
          value={data?.totals.total_accounts}
          icon={<Users size={20} />}
          iconColor="text-indigo-500"
          loading={loading}
        />
        <MetricCard
          title="Currently Online"
          value={data?.totals.online}
          icon={<Wifi size={20} />}
          iconColor="text-emerald-500"
          loading={loading}
        />
      </div>

      <div
        className={`rounded-2xl border overflow-hidden ${
          isDarkMode ? 'border-gray-700' : 'border-gray-400'
        }`}
      >
        {/* Wide table: scrolls inside its own container so the page never does. */}
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr
                className={`text-left uppercase tracking-widest text-[10px] ${
                  isDarkMode ? 'bg-gray-900/50 text-slate-400' : 'bg-gray-100 text-slate-500'
                }`}
              >
                <th className="px-4 py-3 font-bold">Company</th>
                <th className="px-4 py-3 font-bold text-right">Collected MTD</th>
                <th className="px-4 py-3 font-bold text-right">Receivables</th>
                <th className="px-4 py-3 font-bold text-right">Accounts</th>
                <th className="px-4 py-3 font-bold text-right">Online</th>
                <th className="px-4 py-3 font-bold text-right">Applications</th>
              </tr>
            </thead>
            <tbody>
              {(data?.sources ?? []).map((row) => (
                <tr
                  key={row.source}
                  className={`border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-300'}`}
                >
                  <td className="px-4 py-3 font-semibold">
                    {row.label}
                    {!row.reachable && (
                      <span className="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-500/10 text-red-500 border border-red-500/20">
                        Unreachable
                      </span>
                    )}
                  </td>

                  {row.overview ? (
                    <>
                      <td className="px-4 py-3 text-right font-bold">
                        {formatCurrency(row.overview.revenue_mtd)}
                      </td>
                      <td className="px-4 py-3 text-right">{formatCurrency(row.overview.receivables)}</td>
                      <td className="px-4 py-3 text-right">
                        {row.overview.total_accounts.toLocaleString()}
                      </td>
                      <td className="px-4 py-3 text-right">
                        {row.overview.sessions.online.toLocaleString()}
                      </td>
                      <td className="px-4 py-3 text-right">
                        {row.overview.applications_mtd.toLocaleString()}
                      </td>
                    </>
                  ) : (
                    <td
                      colSpan={5}
                      className={`px-4 py-3 text-right text-xs ${
                        isDarkMode ? 'text-slate-500' : 'text-slate-500'
                      }`}
                    >
                      {row.error || 'No data'}
                    </td>
                  )}
                </tr>
              ))}

              {!loading && (data?.sources.length ?? 0) === 0 && (
                <tr>
                  <td
                    colSpan={6}
                    className={`px-4 py-6 text-center ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}
                  >
                    No sources are enabled. Check MONITOR_SOURCE_*_ENABLED in the backend .env.
                  </td>
                </tr>
              )}

              {loading && !data && (
                <tr>
                  <td
                    colSpan={6}
                    className={`px-4 py-6 text-center ${isDarkMode ? 'text-slate-500' : 'text-slate-500'}`}
                  >
                    Loading...
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </PageShell>
  );
};

export default Consolidated;
