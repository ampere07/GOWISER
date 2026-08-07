import apiClient from '../config/api';
import { HealthCheckResponse, LoginResponse, UserData, UserPreferences } from '../types/api';

export const login = async (email: string, password: string): Promise<LoginResponse> => {
  const response = await apiClient.post<LoginResponse>('/login', { email, password });
  return response.data;
};

export const logout = async (): Promise<void> => {
  await apiClient.post('/logout');
};

/**
 * Confirms the cookie session is still valid on the server. localStorage
 * surviving a browser restart does not mean the session did.
 */
export const me = async (): Promise<UserData> => {
  const response = await apiClient.get<{ status: string; data: { user: UserData } }>('/me');
  return response.data.data.user;
};

/**
 * Saves the portal-wide refresh intervals.
 *
 * Announces the change on the window afterwards. The signed-in user lives in
 * App's state and is read through PermissionContext by every dashboard, so the
 * Settings page has no way to push a new value down to them — and without the
 * event the timer on an already-open Group Overview would keep running at the
 * old interval until the next full reload.
 *
 * That patch only reaches *this* browser. Other signed-in sessions pick the new
 * interval up when they next call /me, which is on load — acceptable, because
 * the setting exists to bound sustained load rather than to take effect within
 * the second, and the alternative is a push channel this portal does not have.
 */
export const updatePreferences = async (
  preferences: UserPreferences
): Promise<UserPreferences> => {
  const response = await apiClient.put<{
    status: string;
    data: { preferences: UserPreferences };
  }>('/me/preferences', preferences);

  const saved = response.data.data.preferences;

  window.dispatchEvent(
    new CustomEvent<Partial<UserData>>('auth:user-updated', { detail: { preferences: saved } })
  );

  return saved;
};

export const healthCheck = async (): Promise<HealthCheckResponse> => {
  const response = await apiClient.get<HealthCheckResponse>('/health');
  return response.data;
};
