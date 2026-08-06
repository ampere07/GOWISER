import { useEffect, useRef, useState } from 'react';
import { usePermissions } from './usePermissions';
import { UserPreferences } from '../types/api';

/**
 * Re-runs a callback on the interval this user chose in Settings.
 *
 * ── Why the callback lives in a ref ───────────────────────────────────
 *
 * The obvious `useEffect(() => setInterval(fn, ms), [fn, ms])` restarts the
 * timer every time `fn` changes identity — which, for a callback closing over
 * page state, is every render. On a thirty-second interval that means the timer
 * is torn down and rebuilt before it ever fires, and the refresh silently never
 * happens. Holding the callback in a ref and depending only on the interval
 * means the timer is created once per interval change and always calls the
 * latest closure.
 *
 * ── Why it pauses when the tab is hidden ──────────────────────────────
 *
 * These screens fan out across every monitored database, and the MikroTik one
 * reaches routers that are also serving live authentication. A dashboard left
 * open on a forgotten tab for a fortnight would keep paying that cost for nobody
 * — so the timer stops on `visibilitychange` and fires once immediately on
 * return, which is also what the viewer wants: the first thing they see is
 * current rather than a fortnight stale.
 *
 * Returns the timestamp of the last completed run, for the "Updated HH:MM:SS"
 * line these screens carry. A refresh with no visible evidence it happened is
 * indistinguishable from one that is not running.
 */
export const useAutoRefresh = (
  key: keyof UserPreferences,
  onRefresh: () => void,
  /** Suspends the timer — while a dialog is open, or a request is in flight. */
  paused = false
): { seconds: number; lastRun: Date | null } => {
  const { user } = usePermissions();

  const seconds = Number(user?.preferences?.[key] ?? 0);

  const [lastRun, setLastRun] = useState<Date | null>(null);

  const callback = useRef(onRefresh);
  callback.current = onRefresh;

  const pausedRef = useRef(paused);
  pausedRef.current = paused;

  useEffect(() => {
    if (seconds <= 0) {
      return;
    }

    const run = () => {
      if (pausedRef.current || document.hidden) {
        return;
      }

      callback.current();
      setLastRun(new Date());
    };

    const timer = setInterval(run, seconds * 1000);

    // A tab returning to the foreground refreshes at once rather than waiting
    // out the remainder of an interval that elapsed while nobody was looking.
    const onVisible = () => {
      if (!document.hidden) {
        run();
      }
    };

    document.addEventListener('visibilitychange', onVisible);

    return () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [seconds]);

  return { seconds, lastRun };
};

export default useAutoRefresh;
