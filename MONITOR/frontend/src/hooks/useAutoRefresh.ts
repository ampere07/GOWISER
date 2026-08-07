import { useEffect, useRef, useState } from 'react';
import { usePermissions } from './usePermissions';
import { toastRefreshed } from '../components/common/Toast';
import { UserPreferences } from '../types/api';

/**
 * How many screens are currently asking every timer to hold off.
 *
 * A counter rather than a boolean because two things can be open at once — a
 * drill-down modal over a page that is also mid-dialog — and the second one
 * closing must not un-pause on behalf of the first.
 *
 * Module state rather than context: the thing that needs to read it is
 * Dashboard's poll, which sits *above* every page that would set it, so a
 * provider would have to wrap the whole tree to carry a flag one level upward.
 */
let suspensions = 0;

/**
 * Whether any open screen has asked for refreshes to hold.
 *
 * Read by the callers that own a timer. Deliberately a plain function rather
 * than a hook: a timer callback is not a render, and re-rendering the dashboard
 * every time a modal opens in order to notice would be the wrong mechanism
 * entirely.
 */
export const isRefreshSuspended = (): boolean => suspensions > 0;

/**
 * Holds every auto-refresh — this page's and the dashboard-wide poll — while
 * `active`.
 *
 * A table reloading underneath somebody reading row forty is worse than a stale
 * one, and that is just as true of the poll that lives in Dashboard as of the
 * page's own timer. Before this, opening a drill-down paused the page's timer
 * and the poll carried on regardless, pulling the rows out from under the reader
 * anyway.
 *
 * Registered on mount and released on unmount, so a page that unmounts with a
 * dialog open — a permission change moving somebody off the section — cannot
 * leave the poll suspended for the rest of the session.
 */
export const useSuspendRefresh = (active: boolean): void => {
  useEffect(() => {
    if (!active) return;

    suspensions += 1;

    return () => {
      suspensions = Math.max(0, suspensions - 1);
    };
  }, [active]);
};

/**
 * Re-runs a callback on the portal-wide interval set in Settings.
 *
 * ── Why the interval is not this user's ───────────────────────────────
 *
 * It used to be. The value now comes from one portal-wide setting and reaches
 * this hook the same way it always did — on the session payload, under
 * `preferences` — so nothing here had to change to follow it. The reason for
 * the move is that this number is a statement about production load rather than
 * a personal taste: it decides how often MONITOR fans out across every
 * monitored database, and on the MikroTik screen how often it reaches routers
 * that are simultaneously serving live authentication. See
 * AppSetting::refreshIntervals.
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

  // A page that pauses its own timer pauses the dashboard-wide poll with it.
  // Pausing only the local one left the poll free to reload the same table from
  // above, which is the state this was meant to prevent.
  useSuspendRefresh(paused);

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

      // The same notice a manual refresh raises, so the two are
      // indistinguishable to the reader — which is the point: a figure that
      // just moved on its own should say so.
      toastRefreshed();
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
