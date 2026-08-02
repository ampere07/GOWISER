import { useEffect, useState } from 'react';
import { settingsColorPaletteService } from '../services/settingsColorPaletteService';
import bundledLogo from '../assets/gowiserlogo.png';

/**
 * The portal's logo: whatever was uploaded on the Settings screen, falling back
 * to the mark bundled with the build.
 *
 * The bundled asset is returned *synchronously* as the initial value rather than
 * null, so the header and the login screen never flash an empty box while the
 * fetch is in flight. An uploaded logo swaps in a moment later.
 *
 * The endpoint behind this is deliberately public — the login screen renders the
 * logo before a session exists — and the service caches it for a minute, so the
 * three or four components calling this hook on one page share a single request.
 */
export const useLogo = (): string => {
  const [logo, setLogo] = useState<string>(bundledLogo);

  useEffect(() => {
    let cancelled = false;

    settingsColorPaletteService
      .getLogo()
      .then((uploaded) => {
        if (!cancelled && uploaded) {
          setLogo(uploaded);
        }
      })
      // A failed fetch keeps the bundled mark. A missing logo is not worth an
      // error on screen, and this runs on the unauthenticated login page where
      // there is nowhere sensible to put one.
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, []);

  return logo;
};
