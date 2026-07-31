import React, { useState } from 'react';
import { ArrowRight, Eye, EyeOff, ShieldCheck } from 'lucide-react';
import { login } from '../services/api';
import { UserData } from '../types/api';
import { usePalette } from '../hooks/usePalette';
import gowiserlogo from '../assets/gowiserlogo.png';

interface LoginProps {
  onLogin: (userData: UserData) => void;
}

const Login: React.FC<LoginProps> = ({ onLogin }) => {
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');
  const [showSuspendedModal, setShowSuspendedModal] = useState(false);
  const palette = usePalette();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!identifier || !password) {
      setError('Please enter your username and password');
      return;
    }

    setIsLoading(true);
    setError('');

    try {
      const response = await login(identifier, password);

      if (response.status === 'success') {
        onLogin(response.data.user);
      } else {
        setError('Login failed. Please try again.');
      }
    } catch (err: any) {
      if (err.response?.status === 403 && err.response?.data?.status === 'suspended') {
        setShowSuspendedModal(true);
      } else {
        setError(err.response?.data?.message || 'Invalid credentials. Please try again.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <>
      <style>{`
        * {
          -webkit-font-smoothing: antialiased;
          -moz-osx-font-smoothing: grayscale;
          text-rendering: optimizeLegibility;
        }
        @media (max-width: 768px) {
          .login-container {
            flex-direction: column !important;
            background-color: #f8fafc !important;
            padding: 20px !important;
            box-shadow: none !important;
            min-height: 100vh !important;
            border-radius: 0 !important;
          }
          .login-left {
            order: 2 !important;
            background: linear-gradient(135deg, ${palette.primary} 0%, ${palette.secondary} 100%) !important;
            border-radius: 30px !important;
            padding: 40px 25px !important;
            margin-bottom: 40px !important;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4) !important;
            width: 100% !important;
          }
          .login-right {
            order: 1 !important;
            display: contents !important;
          }
          .logo-section {
            order: 1 !important;
            width: 100% !important;
            padding: 40px 0 20px 0 !important;
            background: transparent !important;
          }
          .notice-section {
            order: 3 !important;
            width: 100% !important;
            padding: 0 0 40px 0 !important;
            background: transparent !important;
          }
          .login-input {
            border-radius: 50px !important;
            padding: 16px 25px !important;
            font-size: 16px !important;
            border: none !important;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1) !important;
          }
          .login-button {
            border-radius: 50px !important;
            padding: 16px !important;
            font-size: 16px !important;
            text-transform: uppercase !important;
            letter-spacing: 1px !important;
            color: ${palette.primary} !important;
          }
          .login-button:disabled {
            color: #ffffff !important;
            background-color: #6b7280 !important;
          }
          .portal-title {
            color: ${palette.primary} !important;
            font-size: 30px !important;
          }
        }
      `}</style>

      <div style={{ display: 'flex', minHeight: '100vh', backgroundColor: '#f3f4f6' }}>
        <div
          className="login-container"
          style={{
            display: 'flex',
            width: '100%',
            maxWidth: '1200px',
            margin: 'auto',
            backgroundColor: '#ffffff',
            borderRadius: '16px',
            overflow: 'hidden',
            boxShadow: '0 10px 30px rgba(0, 0, 0, 0.1)',
          }}
        >
          <div
            className="login-left"
            style={{
              flex: 1,
              background: `linear-gradient(135deg, ${palette.primary} 0%, ${palette.secondary} 100%)`,
              padding: '60px 50px',
              display: 'flex',
              flexDirection: 'column',
              justifyContent: 'center',
              borderTopRightRadius: '16px',
              borderBottomRightRadius: '16px',
              boxShadow: '4px 0 15px rgba(0, 0, 0, 0.1)',
            }}
          >
            <div style={{ marginBottom: '40px' }}>
              <h1 style={{ fontSize: '32px', fontWeight: 700, color: '#ffffff', marginBottom: '10px' }}>
                Executive Access
              </h1>
              <p style={{ fontSize: '14px', color: '#ffffff', opacity: 0.9, fontWeight: 700 }}>
                Sign in to view management dashboards.
              </p>
            </div>

            <form onSubmit={handleSubmit}>
              <div style={{ marginBottom: '24px' }}>
                <input
                  type="text"
                  value={identifier}
                  onChange={(e) => setIdentifier(e.target.value)}
                  className="login-input"
                  autoComplete="username"
                  style={{
                    width: '100%',
                    padding: '14px',
                    backgroundColor: '#ffffff',
                    border: '1px solid #d1d5db',
                    borderRadius: '8px',
                    color: '#111827',
                    fontSize: '15px',
                    outline: 'none',
                    fontWeight: 600,
                  }}
                  placeholder="Username or Email"
                />
              </div>

              <div style={{ marginBottom: '32px', position: 'relative' }}>
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="login-input"
                  autoComplete="current-password"
                  style={{
                    width: '100%',
                    padding: '14px',
                    paddingRight: '50px',
                    backgroundColor: '#ffffff',
                    border: '1px solid #d1d5db',
                    borderRadius: '8px',
                    color: '#111827',
                    fontSize: '15px',
                    outline: 'none',
                    fontWeight: 600,
                  }}
                  placeholder="Password"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  style={{
                    position: 'absolute',
                    right: '20px',
                    top: '50%',
                    transform: 'translateY(-50%)',
                    background: 'none',
                    border: 'none',
                    cursor: 'pointer',
                    color: '#6b7280',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 10,
                  }}
                >
                  {showPassword ? <EyeOff size={20} /> : <Eye size={20} />}
                </button>
              </div>

              {error && (
                <div
                  style={{
                    color: '#dc2626',
                    marginBottom: '20px',
                    fontSize: '14px',
                    backgroundColor: '#fee2e2',
                    padding: '12px',
                    borderRadius: '6px',
                  }}
                >
                  {error}
                </div>
              )}

              <button
                type="submit"
                disabled={isLoading}
                className="login-button"
                style={{
                  width: '100%',
                  padding: '16px',
                  backgroundColor: isLoading ? '#6b7280' : '#ffffff',
                  color: isLoading ? '#ffffff' : palette.primary,
                  border: 'none',
                  borderRadius: '30px',
                  fontSize: '16px',
                  fontWeight: 700,
                  cursor: isLoading ? 'not-allowed' : 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '8px',
                  transition: 'all 0.2s',
                  boxShadow: '0 2px 4px rgba(0, 0, 0, 0.1)',
                }}
                onMouseEnter={(e) => {
                  if (!isLoading) {
                    e.currentTarget.style.transform = 'translateY(-2px)';
                    e.currentTarget.style.boxShadow = '0 4px 8px rgba(0, 0, 0, 0.15)';
                  }
                }}
                onMouseLeave={(e) => {
                  if (!isLoading) {
                    e.currentTarget.style.transform = 'translateY(0)';
                    e.currentTarget.style.boxShadow = '0 2px 4px rgba(0, 0, 0, 0.1)';
                  }
                }}
              >
                {isLoading ? 'SIGNING IN...' : 'SECURE LOGIN'}
                {!isLoading && <ArrowRight size={20} />}
              </button>
            </form>
          </div>

          <div
            className="login-right"
            style={{
              flex: 1,
              backgroundColor: '#ffffff',
              padding: '60px 50px',
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <div className="logo-section" style={{ textAlign: 'center', marginBottom: '40px' }}>
              <img
                src={gowiserlogo}
                alt="GOWISER"
                style={{
                  height: '120px',
                  display: 'block',
                  maxWidth: '100%',
                  objectFit: 'contain',
                  margin: '0 auto 10px',
                }}
                onError={(e) => {
                  e.currentTarget.style.display = 'none';
                }}
              />
              <p style={{ fontSize: '14px', fontWeight: 600, color: '#6b7280', marginTop: '10px' }}>
                Powered by <span style={{ color: palette.primary }}>SYNC</span>
              </p>
            </div>

            <div
              className="notice-section"
              style={{ textAlign: 'center', display: 'flex', flexDirection: 'column', alignItems: 'center' }}
            >
              <h2
                className="portal-title"
                style={{ fontSize: '34px', fontWeight: 700, marginBottom: '15px', color: palette.primary }}
              >
                Monitoring Portal
              </h2>
              <p style={{ fontSize: '16px', color: '#6b7280', maxWidth: '340px', lineHeight: 1.6 }}>
                Consolidated performance across every operating database. Read-only by design — nothing
                you do here changes production data.
              </p>

              <div
                style={{
                  marginTop: '30px',
                  display: 'flex',
                  alignItems: 'center',
                  gap: '8px',
                  color: '#6b7280',
                  fontSize: '13px',
                  fontWeight: 600,
                }}
              >
                <ShieldCheck size={18} color={palette.primary} />
                Authorized personnel only
              </div>
            </div>
          </div>
        </div>
      </div>

      {showSuspendedModal && (
        <div
          style={{
            position: 'fixed',
            inset: 0,
            backgroundColor: 'rgba(0, 0, 0, 0.7)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1000,
            backdropFilter: 'blur(4px)',
          }}
        >
          <div
            style={{
              backgroundColor: '#ffffff',
              borderRadius: '16px',
              padding: '32px',
              maxWidth: '400px',
              width: '90%',
              textAlign: 'center',
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1)',
            }}
          >
            <div
              style={{
                width: '64px',
                height: '64px',
                backgroundColor: '#fee2e2',
                borderRadius: '50%',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                margin: '0 auto 20px',
              }}
            >
              <svg style={{ width: '32px', height: '32px', color: '#dc2626' }} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                />
              </svg>
            </div>
            <h3 style={{ fontSize: '20px', fontWeight: 700, color: '#111827', marginBottom: '12px' }}>
              Account Suspended
            </h3>
            <p style={{ fontSize: '16px', color: '#4b5563', marginBottom: '24px', lineHeight: 1.5 }}>
              Your account has been suspended. Please contact the system administrator.
            </p>
            <button
              onClick={() => setShowSuspendedModal(false)}
              style={{
                width: '100%',
                padding: '12px',
                backgroundColor: palette.primary,
                color: '#ffffff',
                border: 'none',
                borderRadius: '30px',
                fontSize: '16px',
                fontWeight: 700,
                cursor: 'pointer',
                boxShadow: '0 4px 6px rgba(0, 0, 0, 0.1)',
              }}
            >
              CONFIRM
            </button>
          </div>
        </div>
      )}
    </>
  );
};

export default Login;
