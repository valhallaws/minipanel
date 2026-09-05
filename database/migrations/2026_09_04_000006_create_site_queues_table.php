<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('workers')->default(1);
            $table->unsignedTinyInteger('tries')->default(3);
            $table->unsignedInteger('timeout')->default(90);
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['site_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_queues');
    }
};
