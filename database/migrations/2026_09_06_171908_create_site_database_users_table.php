<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_database_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_database_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 32)->unique();
            $table->text('password');
            $table->string('permission')->default('admin');
            $table->string('access')->default('local');
            $table->string('remote_ip')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_database_users');
    }
};
