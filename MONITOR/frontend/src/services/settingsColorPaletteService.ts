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

/**
 * The SYNC platform fee, per billable subscriber per month.
 *
 * Not branding, but it arrives on the same call because the Settings screen is
 * where it is edited and one request is one way for that screen to load
 * half-drawn instead of two.
 */
export interface SyncPriceSettings {
  rate: number;
  /** Billing statuses that are never charged for. Read-only — set by the brief. */
  excluded_statuses: string[];
}

/**
 * The hosting fee, a flat monthly infrastructure charge.
 *
 * Unlike SYNC pricing there is no per-subscriber multiplier — the rate is the
 * total, and it is set once rather than computed.
 */
export interface HostingFeeSettings {
  rate: number;
}

export interface BrandingSettings {
  /** Absolute URL of the uploaded logo, or null to fall back to the bundled mark. */
  logo: string | null;
  palettes: ColorPalette[];
  sync_price: SyncPriceSettings;
  hosting_fee: HostingFeeSettings;
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

  /**
   * Sets the SYNC price per customer.
   *
   * Drops the executive caches as well as the branding one: this figure lands
   * under Expenses on the Executive Dashboard and therefore inside Net Income,
   * and leaving the old total on screen for a cache TTL after someone has just
   * corrected the rate is exactly the moment they would go looking for a bug.
   */
  updateSyncPrice: async (rate: number): Promise<SyncPriceSettings> => {
    const response = await api.put<{ status: string; data: { sync_price: SyncPriceSettings } }>(
      '/settings/sync-price',
      { rate }
    );

    invalidateBranding();
    requestCache.invalidatePrefix('reporting_executive');

    return response.data.data.sync_price;
  },

  /**
   * Sets the hosting fee (flat monthly charge).
   *
   * Drops the same caches as updateSyncPrice — this figure also lands under
   * Expenses on the Executive Dashboard.
   */
  updateHostingFee: async (rate: number): Promise<HostingFeeSettings> => {
    const response = await api.put<{ status: string; data: { hosting_fee: HostingFeeSettings } }>(
      '/settings/hosting-fee',
      { rate }
    );

    invalidateBranding();
    requestCache.invalidatePrefix('reporting_executive');

    return response.data.data.hosting_fee;
  },
};
