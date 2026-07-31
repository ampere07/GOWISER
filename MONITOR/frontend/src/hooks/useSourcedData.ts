import { useEffect, useState } from 'react';
import { useMonitorStore } from '../store/monitorStore';

interface SourcedData<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  source: string;
}

/**
 * Fetches one dashboard payload for the currently selected source, re-running
 * whenever the source changes or the parent bumps refreshToken.
 *
 * Two details that matter:
 *  - previous data is kept on screen while a refresh is in flight, so a poll
 *    does not blank the dashboard every interval
 *  - responses from a superseded request are discarded, so switching source
 *    twice quickly cannot leave the wrong company's numbers on screen
 */
export const useSourcedData = <T,>(
  fetcher: (source: string) => Promise<T>,
  refreshToken: number
): SourcedData<T> => {
  const activeSource = useMonitorStore((state) => state.activeSource);
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!activeSource) {
      return;
    }

    let cancelled = false;
    setLoading(true);

    fetcher(activeSource)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;
        console.error('Dashboard fetch failed:', err);
        setError(
          err?.response?.data?.message || 'Unable to load these figures. The source database may be unreachable.'
        );
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
    // fetcher is defined inline by callers; depending on it would refetch every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeSource, refreshToken]);

  return { data, loading, error, source: activeSource };
};
