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
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('telegram_enabled')->default(false)->after('applied_at');
            $table->text('telegram_bot_token')->nullable()->after('telegram_enabled');
            $table->string('telegram_chat_id', 64)->nullable()->after('telegram_bot_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn(['telegram_enabled', 'telegram_bot_token', 'telegram_chat_id']);
        });
    }
};
