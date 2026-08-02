import api from '../config/api';
import { requestCache } from '../utils/requestCache';

export interface ColorPalette {
  id: number;
  palette_name: string;
  primary: string;
  secondary: string;
  accent: string;
  status: 'active' | 'inactive';
  created_at?: string;
  updated_at?: string;
  updated_by?: string;
}

export interface PaletteFormValues {
  palette_name: string;
  primary: string;
  secondary: string;
  accent: string;
}

export interface BrandingSettings {
  /** Absolute URL of the uploaded logo, or null to fall back to the bundled mark. */
  logo: string | null;
  palettes: ColorPalette[];
}

/**
 * Clears everything that renders in the brand's colours or carries its mark.
 *
 * The palette and logo are read by the sidebar, the header and the login screen
 * through their own cached fetches, so a write has to drop all of them or the
 * page keeps rendering the previous brand until the TTL lapses.
 */
const invalidateBranding = () => {
  requestCache.invalidate('color_palettes_all');
  requestCache.invalidate('color_palette_active');
  requestCache.invalidate('branding_settings');
  requestCache.invalidate('branding_logo');
};

export const settingsColorPaletteService = {
  getAll: async (): Promise<ColorPalette[]> => {
    return requestCache.get(
      'color_palettes_all',
      async () => {
        const response = await api.get<ColorPalette[]>('/settings-color-palette');
        return response.data;
      },
      30000
    );
  },

  getActive: async (): Promise<ColorPalette | null> => {
    return requestCache.get(
      'color_palette_active',
      async () => {
        const response = await api.get<ColorPalette | null>('/settings-color-palette/active');
        return response.data;
      },
      30000
    );
  },

  // ── Branding administration ──────────────────────────────────────────

  /** Logo and palettes in one call: the Settings screen needs both to render. */
  getBranding: async (): Promise<BrandingSettings> =>
    requestCache.get(
      'branding_settings',
      async () => {
        const response = await api.get<{ status: string; data: BrandingSettings }>('/settings');
        return response.data.data;
      },
      30000
    ),

  /** The logo alone. Fetched by the login screen, before a session exists. */
  getLogo: async (): Promise<string | null> =>
    requestCache.get(
      'branding_logo',
      async () => {
        const response = await api.get<{ status: string; data: { logo: string | null } }>(
          '/settings/logo'
        );
        return response.data.data.logo;
      },
      60000
    ),

  uploadLogo: async (file: File): Promise<string | null> => {
    const body = new FormData();
    body.append('logo', file);

    const response = await api.post<{ status: string; data: { logo: string | null } }>(
      '/settings/logo',
      body,
      // Left to the browser so it can add the multipart boundary; the client's
      // JSON default would produce a body Laravel cannot parse.
      { headers: { 'Content-Type': undefined as unknown as string } }
    );

    invalidateBranding();

    return response.data.data.logo;
  },

  deleteLogo: async (): Promise<void> => {
    await api.delete('/settings/logo');
    invalidateBranding();
  },

  createPalette: async (values: PaletteFormValues): Promise<ColorPalette> => {
    const response = await api.post<{ status: string; data: { palette: ColorPalette } }>(
      '/settings/palettes',
      values
    );

    invalidateBranding();

    return response.data.data.palette;
  },

  updatePalette: async (id: number, values: PaletteFormValues): Promise<ColorPalette> => {
    const response = await api.put<{ status: string; data: { palette: ColorPalette } }>(
      `/settings/palettes/${id}`,
      values
    );

    invalidateBranding();

    return response.data.data.palette;
  },

  activatePalette: async (id: number): Promise<ColorPalette[]> => {
    const response = await api.post<{ status: string; data: { palettes: ColorPalette[] } }>(
      `/settings/palettes/${id}/activate`
    );

    invalidateBranding();

    return response.data.data.palettes;
  },

  deletePalette: async (id: number): Promise<void> => {
    await api.delete(`/settings/palettes/${id}`);
    invalidateBranding();
  },
};
