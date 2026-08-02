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
}

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
