import { useEffect, useState } from 'react';
import { useMonitorStore } from '../store/monitorStore';
import { ReportingFilters, ReportingResponse } from '../types/reporting';

interface SectionState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  /**
   * Key of the source that actually answered — not necessarily the selected one.
   * Callers that make a follow-up request for the same figures (the print
   * endpoint) must pass this, or the document will not match the page.
   */
  source: string;
  /** Label of the source that actually answered. */
  sourceLabel: string;
  /**
   * Set when the section could not be served by the selected system and fell
   * through to one that could. Pages surface this so the substitution is visible.
   */
  substituted: boolean;
}

/**
 * Fetches one section payload for the active source and filter set.
 *
 * Three details that matter, and are the reason this is a hook rather than
 * copy-pasted into five pages:
 *
 *  - previous data stays on screen while a refresh is in flight, so the 30-second
 *    poll does not blank the page every interval
 *  - responses from a superseded request are discarded, so changing a filter
 *    twice quickly cannot leave the earlier answer on screen
 *  - the source that answered is reported separately from the one requested,
 *    because a section the selected system cannot serve is answered by one that
 *    can (Tech reads GOWISER even when the app points at NETMANAGER)
 */
export const useReportingSection = <T,>(
  fetcher: (source: string, filters: ReportingFilters) => Promise<ReportingResponse<T>>,
  filters: ReportingFilters,
  refreshToken: number
): SectionState<T> => {
  const activeSource = useMonitorStore((state) => state.activeSource);

  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [source, setSource] = useState('');
  const [sourceLabel, setSourceLabel] = useState('');
  const [substituted, setSubstituted] = useState(false);

  useEffect(() => {
    if (!activeSource) return;

    let cancelled = false;
    setLoading(true);

    fetcher(activeSource, filters)
      .then((result) => {
        if (cancelled) return;

        setData(result.data);
        setSource(result.source);
        setSourceLabel(result.source_label);
        setSubstituted(
          Boolean(result.requested_source) && result.requested_source !== result.source
        );
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;

        console.error('Reporting section fetch failed:', err);
        setError(
          err?.response?.data?.message ||
            'Unable to load this section. The source database may be unreachable.'
        );
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
    // fetcher is a stable service method; filters is one object so a single
    // dependency covers every control on the page.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeSource, filters, refreshToken]);

  return { data, loading, error, source, sourceLabel, substituted };
};
