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
        Schema::create('site_database_grants', function (Blueprint $table) {
            $table->foreignId('site_database_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_database_id')->constrained()->cascadeOnDelete();
            $table->primary(['site_database_user_id', 'site_database_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_database_grants');
    }
};
