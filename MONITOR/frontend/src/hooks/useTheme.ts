import { useEffect, useState } from 'react';
import { themeService } from '../services/themeService';

/**
 * Dark-mode flag, kept in sync with the DOM class.
 *
 * GOWISER repeats this MutationObserver block in a dozen components; here it
 * lives in one place.
 */
export const useTheme = (): boolean => {
  const [isDarkMode, setIsDarkMode] = useState<boolean>(themeService.isDark());

  useEffect(() => themeService.subscribe(setIsDarkMode), []);

  return isDarkMode;
};
