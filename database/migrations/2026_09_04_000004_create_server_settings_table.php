<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_settings', function (Blueprint $table) {
            $table->id();
            $table->string('db_host')->default('127.0.0.1');
            $table->unsignedInteger('db_port')->default(3306);
            $table->string('db_username');
            $table->text('db_password');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_settings');
    }
};
