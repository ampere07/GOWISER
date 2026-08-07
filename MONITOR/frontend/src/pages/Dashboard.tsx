import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Sidebar, { navigableItems, visibleMenuItems } from './Sidebar';
import Header from './Header';
import SubscriberAnalytics from './SubscriberAnalytics';
import Financial from './Financial';
import FieldOperations from './FieldOperations';
import Tech from './Tech';
import Databases from './Databases';
import ExecutiveOverview from './ExecutiveOverview';
import UserManagement from './UserManagement';
import Roles from './Roles';
import AuditTrail from './AuditTrail';
import Settings from './Settings';
import MikrotikRadius from './MikrotikRadius';
import ToastHost, { toastRefreshed } from '../components/common/Toast';
import { UserData } from '../types/api';
import { PermissionContext } from '../hooks/usePermissions';
import { isRefreshSuspended } from '../hooks/useAutoRefresh';
import { useTheme } from '../hooks/useTheme';
import { useMonitorStore } from '../store/monitorStore';
import { monitorService } from '../services/monitorService';
import { reportingService } from '../services/reportingService';
import { ReportingCapabilities, ReportingSection } from '../types/reporting';

interface DashboardProps {
  user: UserData;
  onLogout: () => void;
}

/**
 * The dashboard-wide poll used to run on a build-time constant
 * (REACT_APP_POLL_INTERVAL, defaulting to thirty seconds) that nothing in the
 * product could reach. It now runs on the interval set under Settings →
 * Auto-Refresh, which is the control that claims to govern exactly this.
 *
 * Those two things being separate was the bug: an administrator who set
 * auto-refresh to Off still had every open dashboard re-reading every monitored
 * database twice a minute, and the setting page said otherwise. See
 * AppSetting::refreshIntervals for why the interval is portal-wide.
 */

const Dashboard: React.FC<DashboardProps> = ({ user, onLogout }) => {
  const isDarkMode = useTheme();
  const { activeSource, setSources, markUpdated } = useMonitorStore();

  // The reporting sections advertise their own capabilities. A section is
  // offered when *any* configured system can answer it, not when the selected
  // one can: the money is in NETMANAGER and the technicians are in GOWISER, and
  // a request falls through to whichever database holds what was asked for.
  const [reporting, setReporting] = useState<ReportingCapabilities | null>(null);

  const servableSections: ReportingSection[] | undefined = useMemo(() => {
    if (!reporting) return undefined;

    return (Object.keys(reporting.sections) as ReportingSection[]).filter(
      (section) => reporting.sections[section].length > 0
    );
  }, [reporting]);

  // Flattened to leaves: a parent in the tree is a grouping key, not a section,
  // and landing on one would show a blank screen.
  const allowed = useMemo(
    () =>
      navigableItems(
        visibleMenuItems(user.permissions, servableSections, Boolean(user.is_executive_role))
      ),
    [user.permissions, servableSections, user.is_executive_role]
  );

  /**
   * The effective permission list, shared with every panel below.
   *
   * Memoised on the identity of the list rather than rebuilt each render: it is
   * a context value, and a new object every render would re-render every
   * consumer — which on these pages is most of the widget tree.
   */
  const permissionContext = useMemo(
    () => ({
      permissions: user.permissions ?? [],
      isExecutiveRole: Boolean(user.is_executive_role),
      isFullAccess: Boolean(user.is_full_access),
      user,
    }),
    [user]
  );

  const [activeSection, setActiveSection] = useState(() => allowed[0]?.id ?? 'overview');

  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [isRefreshing, setIsRefreshing] = useState(false);
  // Bumped to tell the visible page to re-fetch, on poll or manual refresh.
  const [refreshToken, setRefreshToken] = useState(0);

  useEffect(() => {
    monitorService
      .getSources()
      .then((result) => setSources(result.sources, result.default))
      .catch((err) => console.error('Failed to load monitoring sources:', err));

    // A failure here leaves servableSections undefined, which the menu treats as
    // "not yet known" and so does not filter on — better to offer a section that
    // may fail on open than to hide one the user has access to.
    reportingService
      .getCapabilities()
      .then(setReporting)
      .catch((err) => console.error('Failed to load reporting capabilities:', err));
  }, [setSources]);

  const triggerRefresh = useCallback(
    (manual = false) => {
      if (manual) {
        setIsRefreshing(true);
        // Manual refresh must bypass the client cache, otherwise the button
        // does nothing for up to ten seconds and looks broken.
        monitorService.invalidate();
        reportingService.invalidate(activeSource);
      }

      setRefreshToken((token) => token + 1);
      markUpdated();

      // Every refresh says so, whether somebody pressed the button or the poll
      // came round. A silent re-read is indistinguishable from a page that has
      // stopped updating, and on a screen quoted out loud that difference
      // matters more than the second of chrome it costs. Repeats collapse into
      // one row rather than stacking — see ToastHost.
      toastRefreshed();

      if (manual) {
        window.setTimeout(() => setIsRefreshing(false), 600);
      }
    },
    [activeSource, markUpdated]
  );

  const pollSeconds = Number(user.preferences?.overview_refresh ?? 0);

  useEffect(() => {
    // Off is off. No timer at all rather than one that fires and returns early,
    // so a portal set to Off costs nothing and cannot be woken by the
    // visibility handler below either.
    if (pollSeconds <= 0) return;

    const poll = () => {
      // Polling a backgrounded tab wastes queries on a production database.
      if (document.visibilityState !== 'visible') return;

      // Held while a drill-down or a dialog is open anywhere below. Reloading
      // the table somebody is reading row forty of is worse than a stale one,
      // and that is as true of this poll as of a page's own timer.
      if (isRefreshSuspended()) return;

      monitorService.invalidate();
      reportingService.invalidate(activeSource);
      triggerRefresh(false);
    };

    const interval = setInterval(poll, pollSeconds * 1000);

    // A tab returning to the foreground refreshes at once rather than waiting
    // out the remainder of an interval that elapsed while nobody was looking.
    const onVisible = () => {
      if (!document.hidden) poll();
    };

    document.addEventListener('visibilitychange', onVisible);

    return () => {
      clearInterval(interval);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [pollSeconds, activeSource, triggerRefresh]);

  // A permission or capability change can pull the current section out from
  // under the user. Move them to the first section still available rather than
  // stranding them on a page that cannot load.
  useEffect(() => {
    if (allowed.length === 0) return;
    if (allowed.some((item) => item.id === activeSection)) return;

    setActiveSection(allowed[0].id);
  }, [allowed, activeSection]);

  const toggleSidebar = () => {
    if (window.innerWidth < 768) {
      setIsMobileMenuOpen((open) => !open);
    } else {
      setSidebarCollapsed((collapsed) => !collapsed);
    }
  };

  const handleSectionChange = (section: string) => {
    setActiveSection(section);

    if (window.innerWidth < 768) {
      setIsMobileMenuOpen(false);
    }
  };

  // Every remaining section owns its own database scope — the reporting modules
  // through their own filter, the administration screens because they read
  // MONITOR's own tables. Nothing is left that reads the header's app-wide
  // selection, so the source switcher stays hidden throughout.
  const hideSourceSwitcher = true;

  const renderContent = () => {
    // A section the role cannot see must not render just because it is in
    // state — permissions are re-checked at render, not only at navigation.
    if (!allowed.some((item) => item.id === activeSection)) {
      const permitted = user.permissions?.includes(activeSection);

      return (
        <div className="p-8">
          <p className={isDarkMode ? 'text-slate-400' : 'text-slate-600'}>
            {permitted
              ? 'This system does not report on that. Pick another section or switch source.'
              : 'You do not have access to this dashboard.'}
          </p>
        </div>
      );
    }

    switch (activeSection) {
      case 'executive-overview':
        return <ExecutiveOverview refreshToken={refreshToken} />;
      case 'users':
        return <UserManagement refreshToken={refreshToken} />;
      case 'roles':
        return <Roles refreshToken={refreshToken} />;
      case 'audit':
        return <AuditTrail refreshToken={refreshToken} />;
      case 'settings':
        return <Settings refreshToken={refreshToken} />;
      case 'subscriber-analytics':
        return <SubscriberAnalytics refreshToken={refreshToken} />;
      case 'financial':
        return <Financial refreshToken={refreshToken} user={user} />;
      case 'field-operations':
        return <FieldOperations refreshToken={refreshToken} />;
      case 'tech':
        return <Tech refreshToken={refreshToken} />;
      case 'mikrotik-radius':
        return <MikrotikRadius refreshToken={refreshToken} />;
      case 'databases':
      default:
        return <Databases refreshToken={refreshToken} />;
    }
  };

  return (
    <PermissionContext.Provider value={permissionContext}>
    <div className={`h-screen flex flex-col overflow-hidden ${isDarkMode ? 'bg-gray-950' : 'bg-gray-50'}`}>
      <div className="flex-shrink-0">
        <Header
          onToggleSidebar={toggleSidebar}
          onRefresh={() => triggerRefresh(true)}
          isRefreshing={isRefreshing}
          hideSourceSwitcher={hideSourceSwitcher}
        />
      </div>

      <div className="flex-1 flex overflow-hidden">
        {isMobileMenuOpen && (
          <div
            className="fixed inset-0 bg-black bg-opacity-50 z-40 md:hidden"
            onClick={() => setIsMobileMenuOpen(false)}
          />
        )}

        <div
          className={`flex-shrink-0 fixed md:relative z-50 transition-all duration-300 top-0 md:top-auto left-0 ${
            isMobileMenuOpen ? 'translate-x-0' : '-translate-x-full'
          } md:translate-x-0 h-screen md:h-auto`}
        >
          <div className="h-full md:h-full">
            <Sidebar
              activeSection={activeSection}
              onSectionChange={handleSectionChange}
              onLogout={onLogout}
              isCollapsed={sidebarCollapsed}
              userRole={user.role}
              userEmail={user.email}
              permissions={user.permissions}
              servableSections={servableSections}
              isExecutiveRole={Boolean(user.is_executive_role)}
            />
          </div>
        </div>

        <div className={`flex-1 overflow-hidden ${isDarkMode ? 'bg-gray-950' : 'bg-gray-50'}`}>
          <div className="h-full overflow-y-auto">{renderContent()}</div>
        </div>
      </div>

      {/* Mounted once at the root rather than per page: a notice raised by the
          poll must survive the user navigating between sections mid-fade. */}
      <ToastHost />
    </div>
    </PermissionContext.Provider>
  );
};

export default Dashboard;
