export interface UserData {
  id: number;
  username: string;
  email: string;
  full_name: string;
  role: string;
  role_id: number | null;
  /**
   * Unrestricted role. Not a permission: it gates actions no grant can confer, such as creating
   * an account. The server re-checks it on every such request — this only decides what is offered.
   */
  is_superadmin?: boolean;
  permissions?: string[] | null;
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
