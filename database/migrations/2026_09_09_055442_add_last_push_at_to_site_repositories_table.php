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
        Schema::table('site_repositories', function (Blueprint $table) {
            $table->timestamp('last_push_at')->nullable()->after('synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_repositories', function (Blueprint $table) {
            $table->dropColumn('last_push_at');
        });
    }
};
