import { create } from 'zustand';
import { MonitorSource } from '../types/monitor';

interface MonitorState {
  sources: MonitorSource[];
  activeSource: string;
  isLoading: boolean;
  error: string | null;
  lastUpdated: number | null;
  // fallback is null when no database has been configured yet, which the
  // reducer below already treats the same as an empty list.
  setSources: (sources: MonitorSource[], fallback: string | null) => void;
  setActiveSource: (source: string) => void;
  setLoading: (loading: boolean) => void;
  setError: (error: string | null) => void;
  markUpdated: () => void;
}

/**
 * Which database the dashboards are currently reading. Kept in a store rather
 * than in Dashboard's state so a deeply nested panel can read it without every
 * page having to thread the prop down.
 */
export const useMonitorStore = create<MonitorState>((set) => ({
  sources: [],
  activeSource: '',
  isLoading: false,
  error: null,
  lastUpdated: null,

  setSources: (sources, fallback) =>
    set((state) => ({
      sources,
      // Keep the user's current selection if it is still available.
      activeSource:
        state.activeSource && sources.some((s) => s.key === state.activeSource)
          ? state.activeSource
          : fallback || sources[0]?.key || '',
    })),

  setActiveSource: (activeSource) => set({ activeSource }),
  setLoading: (isLoading) => set({ isLoading }),
  setError: (error) => set({ error }),
  markUpdated: () => set({ lastUpdated: Date.now() }),
}));
