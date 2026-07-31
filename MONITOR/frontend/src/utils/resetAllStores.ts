import { useMonitorStore } from '../store/monitorStore';
import { requestCache } from './requestCache';

/**
 * Called on login and logout. Without this, the next person to log in on a
 * shared machine sees the previous user's cached figures until the first poll
 * lands.
 */
export const resetAllStores = () => {
  useMonitorStore.setState({
    sources: [],
    activeSource: '',
    isLoading: false,
    error: null,
    lastUpdated: null,
  });

  requestCache.clear();
};
