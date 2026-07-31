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
};
