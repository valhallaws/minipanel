<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_database_users', function (Blueprint $table) {
            $table->boolean('all_databases')->default(true);
        });
        DB::table('site_database_users')->whereNotNull('site_database_id')->update(['all_databases' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_database_users', function (Blueprint $table) {
            $table->dropColumn('all_databases');
        });
    }
};
