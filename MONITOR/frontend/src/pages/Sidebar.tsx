import React, { useEffect, useState } from 'react';
import {
  Activity,
  Database,
  DollarSign,
  HardHat,
  Layers,
  LayoutDashboard,
  LogOut,
  User,
  Users,
  Wallet,
  Wrench,
} from 'lucide-react';
import { useTheme } from '../hooks/useTheme';
import { usePalette } from '../hooks/usePalette';
import { Capability } from '../types/monitor';
import { ReportingSection } from '../types/reporting';

export interface MenuItem {
  id: string;
  label: string;
  icon: React.ElementType;
  /**
   * Section is hidden unless the active source's schema can answer it.
   * Undefined means the section is not tied to one source.
   */
  capability?: Capability;
  /**
   * The operational sections advertise their own capability names, because they
   * are served by a different driver set.
   *
   * Unlike `capability`, an item is *not* hidden when the active source cannot
   * serve it — the sections do not all live in one database, and the request
   * falls through to whichever system can answer. See visibleMenuItems.
   */
  reportingSection?: ReportingSection;
  /** Optional divider label rendered above this item. */
  group?: string;
}

/**
 * The whole navigable surface of this app. Read-only views throughout; there is
 * deliberately no management, configuration or data-entry section.
 *
 * A role's `permissions` array (from the MONITOR roles table) is matched
 * against these ids. An empty permission list shows nothing rather than
 * everything — failing closed is the right default for an executive portal.
 */
export const MENU_ITEMS: MenuItem[] = [
  // The five operational sections, ported from the operating systems' own
  // modules. Ordered subscribers → money → delivery → people, which is how the
  // questions actually get asked.
  {
    id: 'subscriber-analytics',
    label: 'Subscriber Analytics',
    icon: Users,
    reportingSection: 'subscriber_analytics',
    group: 'Reporting',
  },
  { id: 'financial', label: 'Financial', icon: Wallet, reportingSection: 'financial' },
  { id: 'field-operations', label: 'Operations', icon: Wrench, reportingSection: 'operations' },
  { id: 'tech', label: 'Tech', icon: HardHat, reportingSection: 'tech' },
  { id: 'employee', label: 'Employee', icon: User, reportingSection: 'employee' },

  // Executive rollups.
  { id: 'overview', label: 'Overview', icon: LayoutDashboard, capability: 'overview', group: 'Executive' },
  { id: 'operations', label: 'Operations', icon: Activity, capability: 'operations' },
  { id: 'revenue', label: 'Revenue', icon: DollarSign, capability: 'revenue' },
  { id: 'financials', label: 'Financials', icon: Wallet, capability: 'financials' },
  // Spans every source, so it does not depend on the one currently selected.
  { id: 'consolidated', label: 'All Companies', icon: Layers },

  // The only section that writes. Not tied to a source — it is the page that
  // decides what the sources are.
  { id: 'databases', label: 'Databases', icon: Database, group: 'Settings' },
];

interface SidebarProps {
  activeSection: string;
  onSectionChange: (section: string) => void;
  onLogout: () => void;
  isCollapsed?: boolean;
  userRole: string;
  userEmail?: string;
  permissions?: string[] | null;
  capabilities?: Capability[];
  /** Sections that at least one configured system can answer. */
  servableSections?: ReportingSection[];
}

/**
 * Which sections the role may see.
 *
 * Two different rules, because the two families of pages behave differently:
 *
 *  - An executive page is tied to the *selected* source, so it is hidden when
 *    that source's schema cannot produce it.
 *  - An operational section is not. The money lives in NETMANAGER and the
 *    technicians in GOWISER, and a request for a section the selected system
 *    cannot serve falls through to one that can. So the test is whether *any*
 *    configured system can answer it — hiding Tech merely because the user
 *    happens to be pointed at NETMANAGER would make it unreachable, since
 *    NETMANAGER is the default.
 *
 * Passing a list as undefined (before capabilities have loaded) skips that check
 * rather than briefly blanking the menu.
 */
export const visibleMenuItems = (
  permissions?: string[] | null,
  capabilities?: Capability[],
  servableSections?: ReportingSection[]
): MenuItem[] => {
  if (!permissions || permissions.length === 0) {
    return [];
  }

  return MENU_ITEMS.filter((item) => {
    if (!permissions.includes(item.id)) return false;

    if (item.capability && capabilities && !capabilities.includes(item.capability)) {
      return false;
    }

    if (
      item.reportingSection &&
      servableSections &&
      !servableSections.includes(item.reportingSection)
    ) {
      return false;
    }

    return true;
  });
};

const Sidebar: React.FC<SidebarProps> = ({
  activeSection,
  onSectionChange,
  onLogout,
  isCollapsed,
  userRole,
  userEmail,
  permissions,
  capabilities,
  servableSections,
}) => {
  const isDarkMode = useTheme();
  const palette = usePalette();
  const [currentDateTime, setCurrentDateTime] = useState('');
  const [tooltipItem, setTooltipItem] = useState<{ id: string; label: string; y: number } | null>(null);

  useEffect(() => {
    const updateDateTime = () => {
      const now = new Date();
      const dateStr = now.toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: '2-digit',
        year: 'numeric',
      });
      const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
      setCurrentDateTime(`${dateStr} ${timeStr}`);
    };

    updateDateTime();
    const interval = setInterval(updateDateTime, 60000);
    return () => clearInterval(interval);
  }, []);

  const items = visibleMenuItems(permissions, capabilities, servableSections);

  const activeStyle = {
    backgroundColor: `${palette.primary}33`,
    color: palette.primary,
    borderRightWidth: '2px',
    borderRightStyle: 'solid' as const,
    borderRightColor: palette.primary,
  };

  // ---- COLLAPSED MODE ----
  if (isCollapsed) {
    return (
      <div
        className={`w-14 h-full flex flex-col border-r transition-all duration-300 ease-in-out overflow-visible ${
          isDarkMode ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-300'
        }`}
        style={{ position: 'relative' }}
      >
        <nav className="flex-1 py-4 overflow-y-auto overflow-x-visible scrollbar-none">
          {items.map((item) => {
            const IconComponent = item.icon;
            const isActive = activeSection === item.id;

            return (
              <div key={item.id} className="relative group">
                <button
                  onClick={() => onSectionChange(item.id)}
                  onMouseEnter={(e) => {
                    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
                    const parentRect = (e.currentTarget as HTMLElement)
                      .closest('.h-full')
                      ?.getBoundingClientRect();
                    setTooltipItem({ id: item.id, label: item.label, y: rect.top - (parentRect?.top ?? 0) });
                  }}
                  onMouseLeave={() => setTooltipItem(null)}
                  className={`w-full flex items-center justify-center py-3 transition-colors ${
                    isActive
                      ? ''
                      : isDarkMode
                      ? 'text-gray-300 hover:text-white hover:bg-gray-700'
                      : 'text-gray-700 hover:text-black hover:bg-gray-100'
                  }`}
                  style={isActive ? activeStyle : {}}
                >
                  <IconComponent
                    className={`h-5 w-5 ${isActive ? '' : isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}
                    style={isActive ? { color: palette.primary } : {}}
                  />
                </button>

                {tooltipItem?.id === item.id && (
                  <div
                    className={`fixed z-50 left-16 px-3 py-1.5 rounded-md text-xs font-medium shadow-lg whitespace-nowrap pointer-events-none ${
                      isDarkMode
                        ? 'bg-gray-900 text-white border border-gray-700'
                        : 'bg-white text-gray-900 border border-gray-200'
                    }`}
                    style={{ top: `${tooltipItem.y}px`, transform: 'translateY(8px)' }}
                  >
                    {item.label}
                    <div
                      className={`absolute left-0 top-1/2 -translate-x-1 -translate-y-1/2 w-2 h-2 rotate-45 ${
                        isDarkMode
                          ? 'bg-gray-900 border-l border-b border-gray-700'
                          : 'bg-white border-l border-b border-gray-200'
                      }`}
                    />
                  </div>
                )}
              </div>
            );
          })}
        </nav>

        <div
          className={`px-0 py-3 border-t flex-shrink-0 flex justify-center ${
            isDarkMode ? 'border-gray-600' : 'border-gray-300'
          }`}
        >
          <button
            onClick={onLogout}
            className={`p-2 rounded transition-colors ${
              isDarkMode
                ? 'text-gray-400 hover:text-white hover:bg-gray-700'
                : 'text-gray-600 hover:text-black hover:bg-gray-100'
            }`}
            title="Logout"
          >
            <LogOut className="h-5 w-5" />
          </button>
        </div>
      </div>
    );
  }

  // ---- EXPANDED MODE ----
  return (
    <div
      className={`w-64 border-r h-full ${
        isDarkMode ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-300'
      } flex flex-col transition-all duration-300 ease-in-out overflow-hidden`}
    >
      <nav className="flex-1 py-4 overflow-y-auto overflow-x-hidden scrollbar-none">
        {items.map((item, index) => {
          const IconComponent = item.icon;
          const isActive = activeSection === item.id;

          // A group heading belongs to the item that declares it, but only when
          // that item is actually the first of its group left after filtering —
          // otherwise a hidden section leaves a heading over the wrong item, or
          // a heading with nothing under it.
          const heading =
            item.group && items.findIndex((candidate) => candidate.group === item.group) === index
              ? item.group
              : null;

          return (
            <React.Fragment key={item.id}>
              {heading && (
                <p
                  className={`px-4 pt-3 pb-1.5 text-[10px] font-bold uppercase tracking-widest ${
                    isDarkMode ? 'text-gray-500' : 'text-gray-400'
                  }`}
                >
                  {heading}
                </p>
              )}

            <button
              onClick={() => onSectionChange(item.id)}
              className={`w-full flex items-center justify-between px-4 py-3 text-sm transition-colors ${
                isActive
                  ? ''
                  : isDarkMode
                  ? 'text-gray-300 hover:text-white hover:bg-gray-700'
                  : 'text-gray-700 hover:text-black hover:bg-gray-100'
              }`}
              style={isActive ? activeStyle : {}}
            >
              <div className="flex items-center">
                <IconComponent className={`h-5 w-5 mr-3 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`} />
                <span>{item.label}</span>
              </div>
            </button>
            </React.Fragment>
          );
        })}

        {items.length === 0 && (
          <p className={`px-4 py-3 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            No dashboards are assigned to your role. Contact the administrator.
          </p>
        )}
      </nav>

      <div
        className={`px-3 py-3 ${isDarkMode ? 'border-gray-600' : 'border-gray-300'} border-t flex-shrink-0`}
      >
        <div className="mb-3">
          <div className={`text-xs mb-2 text-center ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
            {currentDateTime}
          </div>
          <div className="flex items-center mb-2">
            <div
              className={`w-10 h-10 rounded-full flex items-center justify-center ${
                isDarkMode ? 'bg-gray-700 border-gray-600' : 'bg-gray-200 border-gray-300'
              } border-2`}
            >
              <User className={`h-5 w-5 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`} />
            </div>
            <div className="ml-3 flex-1 min-w-0">
              <div
                className={`text-sm font-medium truncate ${isDarkMode ? 'text-gray-200' : 'text-gray-800'}`}
              >
                {userEmail || 'user@example.com'}
              </div>
              <div className={`text-xs truncate capitalize ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
                {userRole}
              </div>
            </div>
          </div>
          <div className={`h-px ${isDarkMode ? 'bg-gray-700' : 'bg-gray-300'} mb-2`} />
        </div>

        <button
          onClick={onLogout}
          className={`w-full px-3 py-2 ${
            isDarkMode
              ? 'text-gray-300 hover:text-white hover:bg-gray-700'
              : 'text-gray-700 hover:text-black hover:bg-gray-100'
          } rounded transition-colors text-sm flex items-center`}
        >
          <LogOut className="h-4 w-4 mr-2" />
          <span>Logout</span>
        </button>
      </div>
    </div>
  );
};

export default Sidebar;
