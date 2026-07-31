import apiClient from '../config/api';
import { HealthCheckResponse, LoginResponse, UserData } from '../types/api';

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

export const healthCheck = async (): Promise<HealthCheckResponse> => {
  const response = await apiClient.get<HealthCheckResponse>('/health');
  return response.data;
};
