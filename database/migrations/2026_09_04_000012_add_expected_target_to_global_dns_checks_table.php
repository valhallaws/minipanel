<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_dns_checks', function (Blueprint $table) {
            $table->string('expected_target')->nullable()->after('record_type');
        });
    }

    public function down(): void
    {
        Schema::table('global_dns_checks', function (Blueprint $table) {
            $table->dropColumn('expected_target');
        });
    }
};
