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
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('backup_enabled')->default(true)->after('last_backup_path');
            $table->unsignedTinyInteger('backup_retention')->default(14)->after('backup_enabled');
            $table->timestamp('backup_last_verified_at')->nullable()->after('backup_retention');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['backup_enabled', 'backup_retention', 'backup_last_verified_at']);
        });
    }
};
