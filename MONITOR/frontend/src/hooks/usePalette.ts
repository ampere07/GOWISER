import { useEffect, useState } from 'react';
import { ColorPalette, settingsColorPaletteService } from '../services/settingsColorPaletteService';

export const FALLBACK_PALETTE: ColorPalette = {
  id: 0,
  palette_name: 'Fallback',
  primary: '#7c3aed',
  secondary: '#6d28d9',
  accent: '#a78bfa',
  status: 'active',
};

/**
 * The active brand palette, with a fallback so a failed fetch renders a
 * correctly-coloured page rather than an unstyled one.
 */
export const usePalette = (): ColorPalette => {
  const [palette, setPalette] = useState<ColorPalette>(FALLBACK_PALETTE);

  useEffect(() => {
    let cancelled = false;

    settingsColorPaletteService
      .getActive()
      .then((active) => {
        if (!cancelled && active) {
          setPalette(active);
        }
      })
      .catch((err) => console.error('Failed to fetch color palette:', err));

    return () => {
      cancelled = true;
    };
  }, []);

  return palette;
};
