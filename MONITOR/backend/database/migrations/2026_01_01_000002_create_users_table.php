<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->string('email_address')->unique();
            $table->string('first_name')->nullable();
            $table->char('middle_initial', 6)->nullable();
            $table->string('last_name')->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('darkmode', 20)->default('active');
            $table->dateTime('last_login')->nullable();
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();

            $table->index('role_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('users');
    }
};
