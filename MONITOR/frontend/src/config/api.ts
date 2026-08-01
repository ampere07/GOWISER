import axios from 'axios';

/**
 * Name of the CSRF cookie the API issues.
 *
 * Not Laravel's default 'XSRF-TOKEN'. MONITOR's cookies are scoped to the parent
 * domain so that exec.gowiser.ph talking to backend3.gowiser.ph counts as
 * same-site — and a parent-domain cookie is visible to GOWISER, which issues its
 * own 'XSRF-TOKEN'. Sharing that name would mean each app clobbering the other's
 * token.
 *
 * Must match session.xsrf_cookie on the backend (SESSION_XSRF_COOKIE).
 */
const CSRF_COOKIE = 'monitor_xsrf';

const getCookie = (name: string): string | null => {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
  return match ? decodeURIComponent(match[2]) : null;
};

const API_BASE_URL = process.env.REACT_APP_API_BASE_URL as string;

if (!API_BASE_URL) {
  throw new Error('REACT_APP_API_BASE_URL must be defined in .env file');
}

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
  timeout: 60000,
  // Axios has its own XSRF handling and defaults to the 'XSRF-TOKEN' cookie.
  // Left at the default it would pick up GOWISER's token from the shared parent
  // domain and send that instead, which fails CSRF validation here.
  xsrfCookieName: CSRF_COOKIE,
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

let csrfInitialized = false;
let csrfInitializationPromise: Promise<void> | null = null;

export const initializeCsrf = async (): Promise<void> => {
  if (csrfInitialized) {
    return;
  }

  if (csrfInitializationPromise) {
    return csrfInitializationPromise;
  }

  csrfInitializationPromise = (async () => {
    try {
      const baseUrl = API_BASE_URL.replace(/\/api$/, '');
      await axios.get(`${baseUrl}/sanctum/csrf-cookie`, {
        withCredentials: true,
      });
      csrfInitialized = true;
    } catch (error) {
      console.error('CSRF Initialization failed:', error);
      throw error;
    } finally {
      csrfInitializationPromise = null;
    }
  })();

  return csrfInitializationPromise;
};

apiClient.interceptors.request.use(
  async (config: any) => {
    const method = config.method?.toUpperCase();
    const requiresCsrf = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method || '');

    if (requiresCsrf && !csrfInitialized) {
      await initializeCsrf();
    }

    const xsrfToken = getCookie(CSRF_COOKIE);
    if (xsrfToken && requiresCsrf) {
      config.headers = config.headers || {};
      config.headers['X-XSRF-TOKEN'] = xsrfToken;
    }

    return config;
  },
  (error) => Promise.reject(error)
);

apiClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response) {
      const status = error.response.status;

      // CSRF token expired: refresh the cookie and replay the request once.
      if (status === 419) {
        csrfInitialized = false;
        try {
          await initializeCsrf();
          const config = error.config;
          // CSRF_COOKIE, not Laravel's default: on a shared parent domain the
          // default name holds GOWISER's token, and replaying with that would
          // turn a recoverable 419 into a permanent one.
          config.headers['X-XSRF-TOKEN'] = getCookie(CSRF_COOKIE) || '';
          return apiClient(config);
        } catch (retryError) {
          return Promise.reject(retryError);
        }
      }

      // Session expired. App.tsx listens for this and drops back to Login.
      if (status === 401) {
        window.dispatchEvent(new CustomEvent('auth:session-expired'));
      }
    }
    return Promise.reject(error);
  }
);

export default apiClient;
export { API_BASE_URL };
