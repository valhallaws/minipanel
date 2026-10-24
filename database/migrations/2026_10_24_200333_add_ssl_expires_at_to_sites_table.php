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
            $table->timestamp('ssl_expires_at')->nullable()->after('ssl_auto_renew');
            $table->index(['ssl_enabled', 'ssl_expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['ssl_enabled', 'ssl_expires_at']);
            $table->dropColumn('ssl_expires_at');
        });
    }
};
