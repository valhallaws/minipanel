<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('webhook_token', 64)->nullable()->unique()->after('repository');
            $table->text('webhook_secret')->nullable()->after('webhook_token');
            $table->timestamp('last_webhook_at')->nullable()->after('last_deployed_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropUnique(['webhook_token'])->dropColumn(['webhook_token', 'webhook_secret', 'last_webhook_at']));
    }
};
