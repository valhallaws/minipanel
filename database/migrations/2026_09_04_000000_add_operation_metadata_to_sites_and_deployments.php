<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('runtime')->default('unknown')->after('php_version');
            $table->json('artisan_commands')->nullable()->after('runtime');
            $table->json('npm_scripts')->nullable()->after('artisan_commands');
            $table->text('environment')->nullable()->after('npm_scripts');
            $table->timestamp('last_inspected_at')->nullable()->after('last_deployed_at');
        });
        Schema::table('deployments', function (Blueprint $table) {
            $table->json('parameters')->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', fn (Blueprint $table) => $table->dropColumn('parameters'));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['runtime', 'artisan_commands', 'npm_scripts', 'environment', 'last_inspected_at']));
    }
};
