<?php

namespace App\Http\Controllers;

use App\Models\ColorPalette;

/**
 * Brand colours for the portal. Read-only here — the palette is managed by
 * seeding or by an admin working directly in the MONITOR database, matching
 * how the rest of this application avoids write paths.
 */
class SettingsColorPaletteController extends Controller
{
    public function index()
    {
        return response()->json(ColorPalette::orderBy('id')->get());
    }

    public function active()
    {
        return response()->json(ColorPalette::where('status', 'active')->first());
    }
}
