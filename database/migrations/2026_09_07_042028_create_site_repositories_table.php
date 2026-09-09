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
        Schema::create('site_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->uuid('key_token')->unique();
            $table->string('name')->nullable();
            $table->string('url', 512)->nullable();
            $table->string('directory')->nullable();
            $table->text('public_key')->nullable();
            $table->string('branch')->nullable();
            $table->json('branches')->nullable();
            $table->json('commits')->nullable();
            $table->string('project_type')->nullable();
            $table->boolean('prepare_project')->default(true);
            $table->boolean('automatic')->default(false);
            $table->text('webhook_secret')->nullable();
            $table->string('status')->default('draft');
            $table->uuid('operation_token')->nullable();
            $table->json('steps')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->unique(['site_id', 'name']);
            $table->unique(['site_id', 'directory']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_repositories');
    }
};
