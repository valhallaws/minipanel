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
        Schema::create('site_database_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->unsignedBigInteger('resource_id');
            $table->string('resource_name');
            $table->string('token', 32)->unique();
            $table->string('upload_path')->nullable();
            $table->string('status')->default('pending');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_database_operations');
    }
};
