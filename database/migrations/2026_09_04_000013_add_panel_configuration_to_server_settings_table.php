<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('panel_domain')->nullable()->after('id');
            $table->string('panel_path')->default('/var/www/minipanel')->after('panel_domain');
            $table->string('panel_user')->default('www-data')->after('panel_path');
            $table->string('panel_php_version', 10)->default('8.3')->after('panel_user');
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn(['panel_domain', 'panel_path', 'panel_user', 'panel_php_version']);
        });
    }
};
