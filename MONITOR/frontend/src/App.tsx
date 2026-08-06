import React, { useState, useEffect } from 'react';
import './App.css';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import SplashScreen from './components/SplashScreen';
import SessionExpiredModal from './components/SessionExpiredModal';
import { UserData } from './types/api';
import { initializeCsrf } from './config/api';
import { logout as logoutRequest, me } from './services/api';
import { resetAllStores } from './utils/resetAllStores';

function App() {
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [userData, setUserData] = useState<UserData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [sessionExpired, setSessionExpired] = useState(false);

  useEffect(() => {
    const initialize = async () => {
      try {
        await initializeCsrf();
      } catch (error) {
        console.error('Failed to initialize CSRF:', error);
      }

      // localStorage surviving a browser restart says nothing about whether
      // the server session did, so confirm with /me before showing anything.
      const stored = localStorage.getItem('authData');

      if (stored) {
        try {
          const user = await me();
          setUserData(user);
          setIsLoggedIn(true);
          localStorage.setItem('authData', JSON.stringify(user));
        } catch (error) {
          localStorage.removeItem('authData');
          setUserData(null);
          setIsLoggedIn(false);
        }
      }

      setIsLoading(false);
    };

    initialize();
  }, []);

  useEffect(() => {
    const handleSessionExpired = () => {
      // Only worth surfacing to someone who thinks they are logged in.
      if (localStorage.getItem('authData')) {
        setSessionExpired(true);
      }
    };

    window.addEventListener('auth:session-expired', handleSessionExpired);
    return () => window.removeEventListener('auth:session-expired', handleSessionExpired);
  }, []);

  /**
   * A page changed something about the signed-in user.
   *
   * Currently the refresh interval, saved on the Settings page. The user object
   * lives here and is read through PermissionContext by every dashboard, so a
   * page that changes it has no way to push the new value down — an event is the
   * shortest path that does not turn `user` into a global store for one field.
   *
   * Merged rather than replaced: the event carries only what changed, and a
   * partial object would drop the permission list the whole app gates on.
   */
  useEffect(() => {
    const handleUserUpdated = (event: Event) => {
      const patch = (event as CustomEvent<Partial<UserData>>).detail;

      if (!patch) return;

      setUserData((current) => {
        if (!current) return current;

        const next = { ...current, ...patch };
        localStorage.setItem('authData', JSON.stringify(next));

        return next;
      });
    };

    window.addEventListener('auth:user-updated', handleUserUpdated);
    return () => window.removeEventListener('auth:user-updated', handleUserUpdated);
  }, []);

  const handleLogin = (user: UserData) => {
    resetAllStores();
    localStorage.setItem('authData', JSON.stringify(user));
    setUserData(user);
    setIsLoggedIn(true);
  };

  const handleLogout = async () => {
    try {
      await logoutRequest();
    } catch (error) {
      // A failed logout call must not strand the user in the app.
      console.error('Logout request failed:', error);
    }

    resetAllStores();
    localStorage.removeItem('authData');
    setUserData(null);
    setIsLoggedIn(false);
    setSessionExpired(false);
  };

  if (isLoading) {
    return <SplashScreen />;
  }

  if (isLoggedIn && userData) {
    return (
      <>
        <Dashboard user={userData} onLogout={handleLogout} />
        <SessionExpiredModal isOpen={sessionExpired} onConfirm={handleLogout} />
      </>
    );
  }

  return <Login onLogin={handleLogin} />;
}

export default App;
