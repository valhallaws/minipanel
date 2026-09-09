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
        Schema::table('site_databases', function (Blueprint $table) {
            $table->string('collation', 64)->default('utf8mb4_spanish2_ci')->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_databases', function (Blueprint $table) {
            $table->dropColumn('collation');
        });
    }
};
