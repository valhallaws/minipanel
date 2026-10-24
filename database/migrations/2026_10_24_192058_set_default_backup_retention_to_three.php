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
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedTinyInteger('backup_retention')->default(3)->change();
        });

        DB::table('sites')->where('backup_retention', 14)->update(['backup_retention' => 3]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedTinyInteger('backup_retention')->default(14)->change();
        });
    }
};
