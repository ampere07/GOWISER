import React from 'react';
import { Menu, Moon, RefreshCw, Sun, Database } from 'lucide-react';
import { useTheme } from '../hooks/useTheme';
import { usePalette } from '../hooks/usePalette';
import { themeService } from '../services/themeService';
import { useMonitorStore } from '../store/monitorStore';
import { useLogo } from '../hooks/useLogo';

interface HeaderProps {
  onToggleSidebar: () => void;
  onRefresh: () => void;
  isRefreshing: boolean;
  /**
   * Hides the source switcher.
   *
   * Set on the reporting sections, which carry their own database filter — one
   * that also offers "All databases". Showing both would give two controls for
   * the same idea, only one of which the page obeys.
   */
  hideSourceSwitcher?: boolean;
}

const Header: React.FC<HeaderProps> = ({
  onToggleSidebar,
  onRefresh,
  isRefreshing,
  hideSourceSwitcher,
}) => {
  const logo = useLogo();
  const isDarkMode = useTheme();
  const palette = usePalette();
  const { sources, activeSource, setActiveSource, lastUpdated } = useMonitorStore();

  return (
    <header
      className={`${
        isDarkMode ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-300'
      } border-b h-16 flex items-center px-4`}
    >
      <div className="flex items-center space-x-4">
        <button
          onClick={onToggleSidebar}
          className={`${
            isDarkMode ? 'text-gray-400 hover:text-white' : 'text-gray-600 hover:text-black'
          } p-2 transition-colors cursor-pointer`}
          type="button"
          aria-label="Toggle navigation"
        >
          <Menu className="h-5 w-5" />
        </button>

        <div className="flex flex-col items-center space-y-1">
          <img
            src={logo}
            alt="GOWISER"
            className="h-10 object-contain"
            onError={(e) => {
              e.currentTarget.style.display = 'none';
            }}
          />
          <h1 className={`${isDarkMode ? 'text-white' : 'text-gray-900'} text-xs font-semibold`}>
            Executive Dashboard
          </h1>
        </div>
      </div>

      <div className="flex-1" />

      <div className="flex items-center space-x-2 sm:space-x-3">
        {/* Source switcher: which database the dashboards are reading. Hidden
            when only one source is configured, since there is nothing to pick,
            and on pages that carry their own database filter. */}
        {sources.length > 1 && !hideSourceSwitcher && (
          <div className="flex items-center gap-2">
            <Database className={`h-4 w-4 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`} />
            <select
              value={activeSource}
              onChange={(e) => setActiveSource(e.target.value)}
              className={`text-sm rounded-lg px-3 py-1.5 border outline-none cursor-pointer ${
                isDarkMode
                  ? 'bg-gray-900 border-gray-700 text-gray-200'
                  : 'bg-white border-gray-300 text-gray-800'
              }`}
              style={{ borderColor: palette.primary }}
            >
              {sources.map((source) => (
                <option key={source.key} value={source.key}>
                  {source.label}
                </option>
              ))}
            </select>
          </div>
        )}

        {lastUpdated && (
          <span className={`hidden md:inline text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-500'}`}>
            Updated {new Date(lastUpdated).toLocaleTimeString()}
          </span>
        )}

        <button
          onClick={onRefresh}
          disabled={isRefreshing}
          className={`p-2 rounded-full transition-colors ${
            isDarkMode ? 'text-gray-400 hover:bg-gray-700' : 'text-gray-600 hover:bg-gray-100'
          } ${isRefreshing ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
          title="Refresh now"
        >
          <RefreshCw className={`h-5 w-5 ${isRefreshing ? 'animate-spin' : ''}`} />
        </button>

        <button
          onClick={() => themeService.toggle()}
          className={`p-2 rounded-full transition-colors ${
            isDarkMode ? 'text-yellow-400 hover:bg-gray-700' : 'text-gray-600 hover:bg-gray-100'
          }`}
          title={isDarkMode ? 'Switch to Light Mode' : 'Switch to Dark Mode'}
        >
          {isDarkMode ? <Sun className="h-5 w-5" /> : <Moon className="h-5 w-5" />}
        </button>
      </div>
    </header>
  );
};

export default Header;
