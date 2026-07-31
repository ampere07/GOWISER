import React from 'react';
import { Clock } from 'lucide-react';
import { usePalette } from '../hooks/usePalette';

interface SessionExpiredModalProps {
  isOpen: boolean;
  onConfirm: () => void;
}

const SessionExpiredModal: React.FC<SessionExpiredModalProps> = ({ isOpen, onConfirm }) => {
  const palette = usePalette();

  if (!isOpen) return null;

  return (
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
            backgroundColor: '#ede9fe',
            borderRadius: '50%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            margin: '0 auto 20px',
          }}
        >
          <Clock size={32} color={palette.primary} />
        </div>
        <h3 style={{ fontSize: '20px', fontWeight: 700, color: '#111827', marginBottom: '12px' }}>
          Session Expired
        </h3>
        <p style={{ fontSize: '16px', color: '#4b5563', marginBottom: '24px', lineHeight: 1.5 }}>
          You have been signed out for security. Please log in again to continue.
        </p>
        <button
          onClick={onConfirm}
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
          BACK TO LOGIN
        </button>
      </div>
    </div>
  );
};

export default SessionExpiredModal;
