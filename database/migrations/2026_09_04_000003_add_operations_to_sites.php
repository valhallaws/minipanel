<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('aliases')->nullable()->after('domain');
            $table->string('health_url')->nullable()->after('aliases');
            $table->string('health_status')->nullable()->after('health_url');
            $table->timestamp('health_checked_at')->nullable()->after('health_status');
            $table->timestamp('last_backup_at')->nullable()->after('last_deployed_at');
            $table->string('last_backup_path')->nullable()->after('last_backup_at');
            $table->string('current_commit', 64)->nullable()->after('last_deployed_at');
            $table->string('previous_commit', 64)->nullable()->after('current_commit');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['aliases', 'health_url', 'health_status', 'health_checked_at', 'last_backup_at', 'last_backup_path', 'current_commit', 'previous_commit']));
    }
};
