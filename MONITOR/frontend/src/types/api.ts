export interface UserData {
  id: number;
  username: string;
  email: string;
  full_name: string;
  role: string;
  role_id: number | null;
  /**
   * The effective permission list: the role's, plus per-user grants, minus
   * per-user denials. Computed server-side so the UI hides on exactly what the
   * middleware enforces on — a control that appears and then 403s is worse than
   * one that was never drawn.
   */
  permissions?: string[] | null;
  /**
   * Whether the role itself is one the consolidated executive view is intended
   * for. Checked in addition to the module permission, because that view puts
   * every company's figures on one screen.
   */
  is_executive_role?: boolean;
  /**
   * Whether the role bypasses the permission map outright — Super Admin and
   * Executive. `permissions` above already lists everything for such a role, so
   * this is not what the UI normally gates on; it covers a control keyed on an
   * id that exists only in this codebase and so can never appear in a list the
   * server assembled.
   */
  is_full_access?: boolean;
  /**
   * This user's own refresh intervals, in seconds. Zero means off.
   *
   * Sent with the session rather than fetched separately, so a dashboard never
   * opens on the default interval and then jumps to the user's a moment later.
   */
  preferences?: UserPreferences | null;
}

/** Per-user display preferences. Seconds; 0 is off. */
export interface UserPreferences {
  overview_refresh: number;
  mikrotik_refresh: number;
}

/** The intervals the Settings screen offers — must match User::REFRESH_CHOICES. */
export const REFRESH_CHOICES: { value: number; label: string }[] = [
  { value: 0, label: 'Off' },
  { value: 10, label: '10 seconds' },
  { value: 30, label: '30 seconds' },
  { value: 60, label: '1 minute' },
  { value: 300, label: '5 minutes' },
];

export interface LoginResponse {
  status: string;
  message: string;
  data: {
    user: UserData;
  };
}

export interface HealthCheckResponse {
  status: string;
  message: string;
  data: {
    server: string;
    timestamp: string;
  };
}

export interface ApiResponse<T> {
  status: string;
  message?: string;
  data: T;
}
