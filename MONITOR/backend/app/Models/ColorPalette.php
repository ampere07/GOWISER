<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ColorPalette extends Model
{
    use HasFactory;

    protected $table = 'settings_color_palette';

    protected $fillable = [
        'palette_name',
        'primary',
        'secondary',
        'accent',
        'status',
        'updated_by',
    ];
}
