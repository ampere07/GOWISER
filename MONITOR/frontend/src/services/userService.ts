import apiClient from '../config/api';

export interface MonitorUser {
  id: number;
  username: string;
  email: string;
  full_name: string;
  contact_number: string | null;
  role: string | null;
  role_id: number | null;
  is_superadmin: boolean;
  active: boolean;
  last_login: string | null;
  permissions: string[];
}

export interface AssignableRole {
  id: number;
  name: string;
  description: string | null;
  permissions: string[];
  is_system: boolean;
  /** Unrestricted access, resolved from a flag rather than the stored permission list. */
  is_superadmin: boolean;
}

/** Every field optional: an edit sends only what changed, and a blank password means "keep it". */
export type UpdateUserPayload = Partial<NewUserPayload>;

export interface NewUserPayload {
  username: string;
  email_address: string;
  password: string;
  first_name?: string;
  last_name?: string;
  contact_number?: string;
  role_id?: number | null;
  active?: boolean;
}

/**
 * User administration.
 *
 * Uncached, for the same reason the Databases service is: this screen writes, and someone who has
 * just created an account must see it in the list rather than a copy from before the write.
 */
export const userService = {
  list: async (): Promise<MonitorUser[]> => {
    const response = await apiClient.get<{ data: { users: MonitorUser[] } }>('/users');
    return response.data.data.users ?? [];
  },

  roles: async (): Promise<AssignableRole[]> => {
    const response = await apiClient.get<{ data: { roles: AssignableRole[] } }>('/users/roles');
    return response.data.data.roles ?? [];
  },

  create: async (payload: NewUserPayload): Promise<MonitorUser> => {
    const response = await apiClient.post<{ data: { user: MonitorUser } }>('/users', payload);
    return response.data.data.user;
  },

  /** Partial by design — omitted fields are left as they are, including the password. */
  update: async (id: number, payload: UpdateUserPayload): Promise<MonitorUser> => {
    const response = await apiClient.put<{ data: { user: MonitorUser } }>(`/users/${id}`, payload);
    return response.data.data.user;
  },

  /** Suspend or restore. An `active: false` user is refused at login. */
  setActive: async (id: number, active: boolean): Promise<MonitorUser> => {
    const response = await apiClient.put<{ data: { user: MonitorUser } }>(`/users/${id}`, { active });
    return response.data.data.user;
  },

  remove: async (id: number): Promise<void> => {
    await apiClient.delete(`/users/${id}`);
  },
};
