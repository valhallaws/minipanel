<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_dns_checks', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('record_type', 10)->default('A');
            $table->string('status')->default('queued');
            $table->json('results')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_dns_checks');
    }
};
