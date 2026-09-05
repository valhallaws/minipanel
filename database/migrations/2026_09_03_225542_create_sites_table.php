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
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain')->unique();
            $table->string('repository')->nullable();
            $table->string('branch')->default('main');
            $table->string('path');
            $table->string('php_version')->default('8.3');
            $table->string('status')->default('provisioning');
            $table->boolean('ssl_enabled')->default(false);
            $table->boolean('queue_enabled')->default(false);
            $table->boolean('scheduler_enabled')->default(true);
            $table->timestamp('last_deployed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
