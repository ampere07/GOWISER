import React, { useEffect, useState } from 'react';
import {
  ChevronRight,
  Crown,
  Database,
  HardHat,
  LogOut,
  Radio,
  ScrollText,
  ShieldCheck,
  SlidersHorizontal,
  User,
  UserCog,
  Users,
  Wallet,
  Wrench,
} from 'lucide-react';
import { useTheme } from '../hooks/useTheme';
import { usePalette } from '../hooks/usePalette';
import { ReportingSection } from '../types/reporting';

export interface MenuItem {
  id: string;
  label: string;
  icon: React.ElementType;
  /**
   * The section this item opens, where it is one of the reporting modules.
   *
   * An item is *not* hidden when the active source cannot serve it — the
   * sections do not all live in one database, and a request for one the selected
   * system cannot answer falls through to whichever can. See visibleMenuItems.
   */
  reportingSection?: ReportingSection;
  /**
   * Requires an executive role on top of the permission.
   *
   * The consolidated view puts every company's figures on one screen, and the
   * backend refuses it to a non-executive role even when the module permission
   * is held — so the menu hides it rather than offering a page that 403s.
   */
  executiveOnly?: boolean;
  /** Optional divider label rendered above this item. */
  group?: string;
  /**
   * Sub-items, for a heading that expands rather than navigates.
   *
   * A parent carries no page of its own — its `id` is a grouping key, never a
   * section — so it is filtered on whether any child survived rather than on its
   * own permission. See visibleMenuItems.
   */
  children?: MenuItem[];
}

/**
 * The whole navigable surface of this app.
 *
 * A role's `permissions` array (from the MONITOR roles table) is matched against
 * these ids. An empty permission list shows nothing rather than everything —
 * failing closed is the right default for an executive portal.
 */
export const MENU_ITEMS: MenuItem[] = [
  // Module 5 first: the consolidated C-suite view is where an executive starts,
  // and every module below it is the detail behind one of its panels.
  {
    id: 'executive-overview',
    label: 'Group Overview',
    icon: Crown,
    executiveOnly: true,
    group: 'Executive',
  },

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

  // Administration. Every one of these writes to MONITOR's own tables only —
  // the monitored databases stay read-only at the connection level regardless.
  //
  // Accounts and roles nest under one heading because they are two halves of the
  // same job and are almost always visited together: you assign a role, then go
  // and look at what that role actually means.
  {
    id: 'user-admin',
    label: 'Users',
    icon: Users,
    group: 'Administration',
    children: [
      { id: 'users', label: 'Users Management', icon: UserCog },
      { id: 'roles', label: 'Roles Management', icon: ShieldCheck },
    ],
  },
  { id: 'audit', label: 'Audit Trail', icon: ScrollText },
  { id: 'settings', label: 'Settings', icon: SlidersHorizontal },
  { id: 'databases', label: 'Databases', icon: Database },

  // The one item here that reaches outside MONITOR. Everything above reads a
  // monitored database or edits a local setting; this talks to a live router and
  // can disconnect subscribers.
  //
  // `executiveOnly` for the same reason the Group Overview carries it, and with
  // more at stake: the backend pins this module to the executive role list on
  // top of the module permission (see EnsureExecutiveRole), so a menu entry
  // without the check would offer a page that 403s. It also carries two further
  // permissions beyond the module id — see Permissions::ACTION_MIKROTIK_*.
  {
    id: 'mikrotik-radius',
    label: 'MikroTik RADIUS',
    icon: Radio,
    executiveOnly: true,
  },
];

interface SidebarProps {
  activeSection: string;
  onSectionChange: (section: string) => void;
  onLogout: () => void;
  isCollapsed?: boolean;
  userRole: string;
  userEmail?: string;
  permissions?: string[] | null;
  /** Sections that at least one configured system can answer. */
  servableSections?: ReportingSection[];
  /** Whether the role may open the consolidated executive view. */
  isExecutiveRole?: boolean;
}

/**
 * Which sections the role may see, as a tree.
 *
 * A reporting module is offered when *any* configured system can answer it, not
 * when the currently selected one can. The money lives in NETMANAGER and the
 * technicians in GOWISER, and a request for a section the selected system cannot
 * serve falls through to one that can — so hiding Tech merely because the user
 * happens to be pointed at NETMANAGER would make it unreachable, NETMANAGER
 * being the default.
 *
 * Passing the list as undefined (before capabilities have loaded) skips that
 * check rather than briefly blanking the menu.
 *
 * A parent is kept only when at least one of its children survived. An expander
 * that opens onto nothing is worse than an absent one — it reads as a fault.
 */
export const visibleMenuItems = (
  permissions?: string[] | null,
  servableSections?: ReportingSection[],
  isExecutiveRole = false
): MenuItem[] => {
  if (!permissions || permissions.length === 0) {
    return [];
  }

  const allowed = (item: MenuItem): boolean => {
    if (!permissions.includes(item.id)) return false;

    // The second gate on the consolidated view. Held separately from the module
    // permission because that view is not access a custom role should acquire
    // sideways — the backend enforces the same rule and would 403.
    if (item.executiveOnly && !isExecutiveRole) return false;

    if (
      item.reportingSection &&
      servableSections &&
      !servableSections.includes(item.reportingSection)
    ) {
      return false;
    }

    return true;
  };

  return MENU_ITEMS.reduce<MenuItem[]>((kept, item) => {
    if (item.children) {
      const children = item.children.filter(allowed);

      if (children.length > 0) {
        kept.push({ ...item, children });
      }

      return kept;
    }

    if (allowed(item)) {
      kept.push(item);
    }

    return kept;
  }, []);
};

/**
 * The tree flattened to the items that actually open a page.
 *
 * Parents are dropped: their id is a grouping key, not a section, and treating
 * one as navigable would land the user on a blank screen. Used by Dashboard to
 * pick a landing section and to re-check access at render.
 */
export const navigableItems = (items: MenuItem[]): MenuItem[] =>
  items.flatMap((item) => item.children ?? [item]);

const Sidebar: React.FC<SidebarProps> = ({
  activeSection,
  onSectionChange,
  onLogout,
  isCollapsed,
  userRole,
  userEmail,
  permissions,
  servableSections,
  isExecutiveRole,
}) => {
  const isDarkMode = useTheme();
  const palette = usePalette();
  const [currentDateTime, setCurrentDateTime] = useState('');
  const [tooltipItem, setTooltipItem] = useState<{ id: string; label: string; y: number } | null>(null);

  /**
   * Which parent groups are open.
   *
   * Seeded from whichever parent owns the active section, so arriving on Roles
   * finds Users already expanded rather than the current page apparently missing
   * from the menu.
   */
  const [expanded, setExpanded] = useState<string[]>(() =>
    MENU_ITEMS.filter((item) => item.children?.some((child) => child.id === activeSection)).map(
      (item) => item.id
    )
  );

  // Re-open when the section changes from elsewhere — the landing redirect, or a
  // permission change that moved the user off a page they can no longer open.
  useEffect(() => {
    const owner = MENU_ITEMS.find((item) =>
      item.children?.some((child) => child.id === activeSection)
    );

    if (owner) {
      setExpanded((current) => (current.includes(owner.id) ? current : [...current, owner.id]));
    }
  }, [activeSection]);

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

  const items = visibleMenuItems(permissions, servableSections, isExecutiveRole);

  const toggle = (id: string) =>
    setExpanded((current) =>
      current.includes(id) ? current.filter((item) => item !== id) : [...current, id]
    );

  /**
   * The active-item treatment, matched to GOWISER's sidebar.
   *
   * A 20% tint of the brand colour (the `33` alpha suffix), the brand colour for
   * the label, and a 2px rule down the right edge. Previously this was a 13%
   * tint and a 3px rule — close enough to look like a mistake rather than a
   * variation when the two apps sit side by side on the same desk, which they
   * do.
   *
   * The alpha suffix is why palettes are stored as six-digit hex: appending to a
   * named colour produces an invalid value that silently drops the tint.
   */
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
      // Keyed per mode so toggling remounts the tree instead of reconciling the
      // icon-only list against the labelled one. Both branches return
      // div > nav > keyed rows, so without this React matches them position by
      // position and carries stale tooltip and expansion state across.
      <div
        key="sidebar-collapsed"
        className={`w-14 h-full flex flex-col border-r transition-all duration-300 ease-in-out overflow-visible ${
          isDarkMode ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-300'
        }`}
        style={{ position: 'relative' }}
      >
        {/* A parent has no page of its own, and there is no room to expand into
            at 56px wide — so the collapsed rail shows the leaves directly. */}
        <nav className="flex-1 py-4 overflow-y-auto overflow-x-visible scrollbar-none">
          {navigableItems(items).map((item) => {
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
          style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom, 0px))' }}
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
      key="sidebar-expanded"
      className={`w-64 border-r h-full ${
        isDarkMode ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-300'
      } flex flex-col transition-all duration-300 ease-in-out overflow-hidden`}
    >
      <nav className="flex-1 py-4 overflow-y-auto overflow-x-hidden scrollbar-none">
        {items.map((item, index) => {
          const IconComponent = item.icon;

          // A group heading belongs to the item that declares it, but only when
          // that item is actually the first of its group left after filtering —
          // otherwise a hidden section leaves a heading over the wrong item, or
          // a heading with nothing under it.
          const heading =
            item.group && items.findIndex((candidate) => candidate.group === item.group) === index
              ? item.group
              : null;

          const isParent = Boolean(item.children?.length);
          const isOpen = expanded.includes(item.id);
          const holdsActive = Boolean(item.children?.some((child) => child.id === activeSection));
          const isActive = !isParent && activeSection === item.id;

          return (
            <React.Fragment key={item.id}>
              {heading && (
                <p
                  className={`px-4 pt-4 pb-1.5 text-[11px] font-semibold uppercase tracking-wider ${
                    isDarkMode ? 'text-gray-500' : 'text-gray-500'
                  }`}
                >
                  {heading}
                </p>
              )}

              <button
                onClick={() => {
                  if (isParent) {
                    toggle(item.id);
                    return;
                  }

                  // Collapse any open group when navigating to a top-level
                  // item, matching GOWISER: an expanded tray left open under
                  // an unrelated active page is noise the reader has to
                  // re-parse every time.
                  setExpanded([]);
                  onSectionChange(item.id);
                }}
                aria-expanded={isParent ? isOpen : undefined}
                className={`w-full flex items-center justify-between px-4 py-3 text-sm transition-colors ${
                  isActive
                    ? ''
                    : isDarkMode
                    ? 'text-gray-300 hover:text-white hover:bg-gray-700'
                    : 'text-gray-700 hover:text-black hover:bg-gray-100'
                }`}
                style={isActive ? activeStyle : {}}
              >
                <div className="flex items-center min-w-0">
                  <IconComponent
                    className={`h-5 w-5 mr-3 flex-shrink-0 ${
                      isDarkMode ? 'text-gray-400' : 'text-gray-600'
                    }`}
                    // A closed parent whose child is the current page still shows
                    // the brand colour, so the active page is never invisible.
                    style={holdsActive && !isOpen ? { color: palette.primary } : {}}
                  />
                  <span className="truncate">{item.label}</span>
                </div>

                {/* Rotating ChevronRight, not a flipping ChevronDown: GOWISER
                    uses the first and the two sidebars are read by the same
                    people minutes apart. */}
                {isParent && (
                  <ChevronRight
                    className={`h-4 w-4 flex-shrink-0 transition-transform duration-200 ${
                      isOpen ? 'rotate-90' : ''
                    } ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}
                  />
                )}
              </button>

              {/* No tinted tray behind the children. GOWISER indents them and
                  leaves the surface flat; a second background here made the
                  group read as a separate panel rather than as nesting. */}
              {isParent && isOpen && (
                <div>
                  {(item.children ?? []).map((child) => {
                    const ChildIcon = child.icon;
                    const childActive = activeSection === child.id;

                    return (
                      <button
                        key={child.id}
                        onClick={() => onSectionChange(child.id)}
                        className={`w-full flex items-center pl-8 pr-4 py-3 text-sm transition-colors ${
                          childActive
                            ? ''
                            : isDarkMode
                            ? 'text-gray-300 hover:text-white hover:bg-gray-700'
                            : 'text-gray-700 hover:text-black hover:bg-gray-100'
                        }`}
                        style={childActive ? activeStyle : {}}
                      >
                        <ChildIcon
                          className={`h-5 w-5 mr-3 flex-shrink-0 ${
                            isDarkMode ? 'text-gray-400' : 'text-gray-600'
                          }`}
                          style={childActive ? { color: palette.primary } : {}}
                        />
                        <span className="truncate">{child.label}</span>
                      </button>
                    );
                  })}
                </div>
              )}
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
        style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom, 0px))' }}
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
