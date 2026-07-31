/**
 * Theme lives in localStorage rather than the database.
 *
 * MONITOR has no write endpoints by design, and a per-user display preference
 * is not worth breaking that for. The DOM class is the single source of truth
 * that components observe.
 */
const STORAGE_KEY = 'theme';

export const themeService = {
  isDark: (): boolean => localStorage.getItem(STORAGE_KEY) !== 'light',

  apply: (dark: boolean) => {
    localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light');

    if (dark) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
  },

  toggle: (): boolean => {
    const next = !themeService.isDark();
    themeService.apply(next);
    return next;
  },

  /**
   * Subscribes to theme changes. Components use this instead of reading
   * localStorage on every render.
   */
  subscribe: (callback: (dark: boolean) => void): (() => void) => {
    const notify = () => callback(themeService.isDark());

    const observer = new MutationObserver(notify);
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class'],
    });

    window.addEventListener('storage', notify);

    return () => {
      observer.disconnect();
      window.removeEventListener('storage', notify);
    };
  },
};
