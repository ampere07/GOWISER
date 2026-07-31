<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('settings_color_palette', function (Blueprint $table) {
            $table->id();
            $table->string('palette_name');
            $table->string('primary', 20);
            $table->string('secondary', 20);
            $table->string('accent', 20);
            $table->enum('status', ['active', 'inactive'])->default('inactive');
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('settings_color_palette');
    }
};
