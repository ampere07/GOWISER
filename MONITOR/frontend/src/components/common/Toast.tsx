import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AlertTriangle, Check, Info, RefreshCw } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';

/**
 * The portal's transient notices.
 *
 * ── Why an event rather than a context ────────────────────────────────
 *
 * The things that need to raise one of these are not all React components. The
 * refresh that fires this most often is a `setInterval` inside Dashboard, and
 * the auto-refresh hook that fires it on the two live screens is called from
 * inside a page that has no idea a toast host exists. Threading a
 * `showToast` callback down to each would mean every intermediate component
 * knowing about a notification system it does not otherwise care about — the
 * same argument that put permissions in a context, arriving at the opposite
 * answer because this one is fire-and-forget and has no state to read.
 *
 * A window event also means a toast raised before the host has mounted is
 * simply dropped, rather than throwing. That is the right failure: a missed
 * confirmation is not worth an error boundary.
 */

export type ToastTone = 'neutral' | 'success' | 'refresh' | 'warning';

export interface ToastPayload {
  message: string;
  tone?: ToastTone;
  /** Milliseconds on screen before the fade begins. */
  duration?: number;
}

const EVENT = 'monitor:toast';

/**
 * How long a notice stays up before fading.
 *
 * Three and a half seconds: long enough to be read by somebody who was looking
 * at something else when it appeared, short enough that it is gone before it
 * becomes furniture. The fade itself is on top of this.
 */
const DEFAULT_DURATION = 3500;

/** Length of the fade-out, and so how long the row survives past its duration. */
const FADE_MS = 400;

/** Raises a toast from anywhere, including outside the React tree. */
export const toast = (message: string, tone: ToastTone = 'neutral', duration?: number): void => {
  window.dispatchEvent(
    new CustomEvent<ToastPayload>(EVENT, { detail: { message, tone, duration } })
  );
};

/** The specific one this app raises most: the page just re-read itself. */
export const toastRefreshed = (): void => toast('Refreshed', 'refresh');

interface LiveToast extends Required<ToastPayload> {
  id: number;
  /** Set once the fade has started, so the row can animate before unmounting. */
  leaving: boolean;
}

const TONE_STYLE: Record<ToastTone, { icon: React.ReactNode; accent: string }> = {
  refresh: { icon: <RefreshCw size={14} />, accent: 'text-blue-500' },
  success: { icon: <Check size={14} />, accent: 'text-emerald-500' },
  warning: { icon: <AlertTriangle size={14} />, accent: 'text-amber-500' },
  neutral: { icon: <Info size={14} />, accent: 'text-gray-400' },
};

/**
 * Renders whatever notices are live. Mount once, near the root.
 *
 * ── Why identical messages collapse ───────────────────────────────────
 *
 * The auto-refresh interval and a manual press of Refresh can land within the
 * same second, and two "Refreshed" rows stacked on top of each other say
 * nothing the first did not. A repeat of a message already on screen restarts
 * that row's timer instead of adding another — the notice stays current without
 * the stack growing under a poll that never stops.
 */
export const ToastHost: React.FC = () => {
  const isDarkMode = useTheme();
  const [toasts, setToasts] = useState<LiveToast[]>([]);

  // Timers are held per toast so a restarted one can cancel its predecessor.
  // A ref rather than state: a pending timeout is not something to re-render on.
  const timers = useRef<Map<number, { fade: number; drop: number }>>(new Map());
  const nextId = useRef(1);

  const clearTimers = useCallback((id: number) => {
    const pair = timers.current.get(id);

    if (pair) {
      window.clearTimeout(pair.fade);
      window.clearTimeout(pair.drop);
      timers.current.delete(id);
    }
  }, []);

  const schedule = useCallback(
    (id: number, duration: number) => {
      clearTimers(id);

      timers.current.set(id, {
        // Two stages: mark it leaving so CSS can fade it, then remove it once
        // the transition has had time to run. Removing it outright would make
        // it vanish rather than fade.
        fade: window.setTimeout(
          () => setToasts((live) => live.map((t) => (t.id === id ? { ...t, leaving: true } : t))),
          duration
        ),
        drop: window.setTimeout(() => {
          setToasts((live) => live.filter((t) => t.id !== id));
          timers.current.delete(id);
        }, duration + FADE_MS),
      });
    },
    [clearTimers]
  );

  useEffect(() => {
    const onToast = (event: Event) => {
      const detail = (event as CustomEvent<ToastPayload>).detail;

      if (!detail?.message) return;

      const tone = detail.tone ?? 'neutral';
      const duration = detail.duration ?? DEFAULT_DURATION;

      setToasts((live) => {
        const existing = live.find((t) => t.message === detail.message && t.tone === tone);

        if (existing) {
          // Same notice again: restart it rather than stack it. Cleared of
          // `leaving` too, so one caught mid-fade comes back rather than
          // finishing its way out.
          schedule(existing.id, duration);

          return live.map((t) => (t.id === existing.id ? { ...t, leaving: false } : t));
        }

        const id = nextId.current++;
        schedule(id, duration);

        return [...live, { id, message: detail.message, tone, duration, leaving: false }];
      });
    };

    window.addEventListener(EVENT, onToast);

    return () => window.removeEventListener(EVENT, onToast);
  }, [schedule]);

  // Every pending timer is dropped on unmount — a logout tears this tree down
  // and a timeout firing into it afterwards would set state on nothing.
  useEffect(() => {
    const pending = timers.current;

    return () => {
      pending.forEach(({ fade, drop }) => {
        window.clearTimeout(fade);
        window.clearTimeout(drop);
      });
      pending.clear();
    };
  }, []);

  if (toasts.length === 0) return null;

  return (
    // Bottom-right and pointer-transparent: this must never sit over a control
    // or intercept a click meant for the page underneath it.
    <div
      className="fixed bottom-4 right-4 z-[60] flex flex-col items-end gap-2 pointer-events-none"
      // Announced rather than shouted: a status region is read by a screen
      // reader when it is convenient, not by interrupting whatever is being read.
      role="status"
      aria-live="polite"
    >
      {toasts.map((item) => {
        const style = TONE_STYLE[item.tone] ?? TONE_STYLE.neutral;

        return (
          <div
            key={item.id}
            style={{ transitionDuration: `${FADE_MS}ms` }}
            className={`flex items-center gap-2 rounded-xl border px-3.5 py-2 text-sm font-medium shadow-lg backdrop-blur transition-all ease-out ${
              item.leaving ? 'opacity-0 translate-y-1' : 'opacity-100 translate-y-0'
            } ${
              isDarkMode
                ? 'bg-gray-900/95 border-gray-700 text-gray-100'
                : 'bg-white/95 border-gray-200 text-gray-900'
            }`}
          >
            <span className={`flex-shrink-0 ${style.accent}`}>{style.icon}</span>
            {item.message}
          </div>
        );
      })}
    </div>
  );
};

export default ToastHost;
