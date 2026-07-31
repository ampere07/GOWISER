import React from 'react';
import { Ban, Banknote, FileText, Lock, Users, Wifi, WifiOff, Wrench } from 'lucide-react';
import PageShell from '../components/common/PageShell';
import Panel from '../components/common/Panel';
import MetricCard from '../components/common/MetricCard';
import { monitorService } from '../services/monitorService';
import { useSourcedData } from '../hooks/useSourcedData';
import { useTheme } from '../hooks/useTheme';
import { Overview as OverviewData, SourcedResponse } from '../types/monitor';

interface OverviewProps {
  refreshToken: number;
}

const Overview: React.FC<OverviewProps> = ({ refreshToken }) => {
  const isDarkMode = useTheme();
  const { data, loading, error } = useSourcedData<SourcedResponse<OverviewData>>(
    (source) => monitorService.getOverview(source),
    refreshToken
  );

  const overview = data?.data;
  const sessions = overview?.sessions;

  const monthLabel = overview?.period.month_start
    ? new Date(overview.period.month_start).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
    : '';

  return (
    <PageShell
      title="Overview"
      subtitle={data ? `${data.source_label} · month to date, ${monthLabel}` : 'Loading source...'}
      error={error}
    >
      {/* Money first: it is what the question is usually about. */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
        <MetricCard
          title="Collected MTD"
          value={overview?.revenue_mtd}
          currency
          icon={<Banknote size={20} />}
          iconColor="text-emerald-500"
          loading={loading}
        />
        <MetricCard
          title="Collected Today"
          value={overview?.revenue_today}
          currency
          icon={<Banknote size={20} />}
          iconColor="text-emerald-500"
          loading={loading}
        />
        <MetricCard
          title="Receivables"
          value={overview?.receivables}
          currency
          icon={<FileText size={20} />}
          iconColor="text-orange-500"
          caption={
            overview ? `${overview.accounts_in_arrears.toLocaleString()} accounts with a balance` : undefined
          }
          loading={loading}
        />
        <MetricCard
          title="Total Accounts"
          value={overview?.total_accounts}
          icon={<Users size={20} />}
          iconColor="text-indigo-500"
          loading={loading}
        />
      </div>

      {/* Network state */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6">
        <MetricCard
          title="Online"
          value={sessions?.online}
          icon={<Wifi size={20} />}
          iconColor="text-emerald-500"
          loading={loading}
        />
        <MetricCard
          title="Offline"
          value={sessions?.offline}
          icon={<WifiOff size={20} />}
          iconColor="text-slate-500"
          loading={loading}
        />
        <MetricCard
          title="Disconnected"
          value={sessions?.disconnected}
          icon={<Ban size={20} />}
          iconColor="text-red-500"
          loading={loading}
        />
        <MetricCard
          title="Restricted"
          value={sessions?.restricted}
          icon={<Lock size={20} />}
          iconColor="text-orange-500"
          loading={loading}
        />
      </div>

      {/* Growth */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <Panel title="Growth this month" scope={monthLabel}>
          <div className="grid grid-cols-2 gap-4">
            <MetricCard
              title="Applications"
              value={overview?.applications_mtd}
              icon={<FileText size={20} />}
              iconColor="text-indigo-500"
              loading={loading}
            />
            <MetricCard
              title="Installed"
              value={overview?.installs_mtd}
              icon={<Wrench size={20} />}
              iconColor="text-emerald-500"
              loading={loading}
            />
          </div>

          {overview && overview.applications_mtd > 0 && (
            <p className={`mt-4 text-xs ${isDarkMode ? 'text-slate-400' : 'text-slate-500'}`}>
              Conversion to install:{' '}
              <span className="font-semibold">
                {Math.round((overview.installs_mtd / overview.applications_mtd) * 100)}%
              </span>{' '}
              of this month's applications are already installed. Applications received late in the month
              will not have been installed yet, so this understates the true rate.
            </p>
          )}
        </Panel>

        <Panel
          title="Collection health"
          scope={overview ? `As of ${new Date(overview.period.as_of).toLocaleString()}` : ''}
        >
          {overview ? (
            <div className="space-y-4">
              <div className="flex items-baseline justify-between">
                <span className={isDarkMode ? 'text-slate-400' : 'text-slate-600'}>
                  Accounts carrying a balance
                </span>
                <span className="text-lg font-bold">
                  {overview.total_accounts > 0
                    ? `${Math.round((overview.accounts_in_arrears / overview.total_accounts) * 100)}%`
                    : '—'}
                </span>
              </div>

              <div className="flex items-baseline justify-between">
                <span className={isDarkMode ? 'text-slate-400' : 'text-slate-600'}>
                  Average balance outstanding
                </span>
                <span className="text-lg font-bold">
                  {overview.accounts_in_arrears > 0
                    ? new Intl.NumberFormat('en-PH', {
                        style: 'currency',
                        currency: 'PHP',
                        maximumFractionDigits: 0,
                      }).format(overview.receivables / overview.accounts_in_arrears)
                    : '—'}
                </span>
              </div>

              <div className="flex items-baseline justify-between">
                <span className={isDarkMode ? 'text-slate-400' : 'text-slate-600'}>
                  Subscribers currently online
                </span>
                <span className="text-lg font-bold">
                  {overview.total_accounts > 0 && sessions
                    ? `${Math.round((sessions.online / overview.total_accounts) * 100)}%`
                    : '—'}
                </span>
              </div>
            </div>
          ) : (
            <p className={isDarkMode ? 'text-slate-500' : 'text-slate-500'}>Loading...</p>
          )}
        </Panel>
      </div>
    </PageShell>
  );
};

export default Overview;
