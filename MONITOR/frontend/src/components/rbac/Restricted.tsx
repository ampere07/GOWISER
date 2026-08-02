import React from 'react';
import { Lock } from 'lucide-react';
import { useTheme } from '../../hooks/useTheme';
import { usePermissions } from '../../hooks/usePermissions';

interface RestrictedProps {
  /** Every one of these must be held for the children to render. */
  require: string | string[];
  children: React.ReactNode;
  /**
   * What to show instead. Omitted renders nothing at all, which is right for a
   * whole panel — an empty card headed "Payables" tells someone there is a
   * payables ledger they cannot see, which is information in itself.
   */
  fallback?: React.ReactNode;
}

/**
 * Renders its children only when the role holds every listed permission.
 *
 * The frontend half of widget-level access control. The backend already strips
 * or masks the data (see App\Support\PayloadMasker), so this is not the security
 * boundary — it is what stops a permitted-but-empty panel appearing, which
 * otherwise reads as a fault rather than as a restriction.
 */
export const Restricted: React.FC<RestrictedProps> = ({ require, children, fallback = null }) => {
  const { can } = usePermissions();
  const required = Array.isArray(require) ? require : [require];

  return can(...required) ? <>{children}</> : <>{fallback}</>;
};

/**
 * The placeholder for a panel that has been withheld rather than left empty.
 *
 * Used where the layout would otherwise collapse — a missing card in a two-up
 * grid leaves its neighbour stretched across the page and looking broken. Says
 * "restricted", never "no data": those are different claims, and the second
 * sends someone to check a database that is working fine.
 */
export const RestrictedPanel: React.FC<{ title: string; height?: number }> = ({
  title,
  height = 200,
}) => {
  const isDarkMode = useTheme();

  return (
    <div
      className={`rounded-xl border border-dashed flex flex-col items-center justify-center text-center px-4 ${
        isDarkMode ? 'border-gray-800 bg-gray-900/40' : 'border-gray-300 bg-gray-50'
      }`}
      style={{ minHeight: height }}
    >
      <Lock size={18} className={isDarkMode ? 'text-gray-600' : 'text-gray-400'} />
      <p className={`mt-2 text-sm font-medium ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
        {title}
      </p>
      <p className={`text-xs mt-0.5 ${isDarkMode ? 'text-gray-600' : 'text-gray-400'}`}>
        Your role does not have access to this widget.
      </p>
    </div>
  );
};

/**
 * A figure the role may not read, in a card that still has to render.
 *
 * Distinct from an em dash. The existing pages render null as "—" meaning "the
 * source does not record this", and a masked revenue figure showing the same
 * glyph would be read as a data gap in the operating system.
 */
export const MaskedValue: React.FC<{ label?: string }> = ({ label = 'Restricted' }) => {
  const isDarkMode = useTheme();

  return (
    <span
      className={`inline-flex items-center gap-1.5 text-base font-medium ${
        isDarkMode ? 'text-gray-600' : 'text-gray-400'
      }`}
      title="Your role does not permit these figures"
    >
      <Lock size={14} />
      {label}
    </span>
  );
};

export default Restricted;
