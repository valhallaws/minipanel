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
            $table->string('server_domain')->nullable()->unique();
            $table->string('pending_domain')->nullable()->unique();
            $table->string('lifecycle_action')->nullable();
            $table->string('lifecycle_token', 32)->nullable();
            $table->text('lifecycle_error')->nullable();
            $table->string('lifecycle_previous_status')->nullable();
            $table->uuid('trash_group')->nullable()->index();
            $table->timestamp('purge_after')->nullable()->index();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['server_domain', 'pending_domain', 'lifecycle_action', 'lifecycle_token', 'lifecycle_error', 'lifecycle_previous_status', 'trash_group', 'purge_after', 'deleted_at']);
        });
    }
};
