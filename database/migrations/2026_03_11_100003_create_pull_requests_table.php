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
        Schema::create('pull_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pr_number');
            $table->string('title');
            $table->string('author');
            $table->string('pr_url');
            $table->string('commit_sha');
            $table->enum('status', ['pending', 'queued', 'reviewing', 'done', 'failed'])->default('pending');
            $table->unsignedTinyInteger('health_score')->nullable(); // 0-100
            $table->text('ai_summary')->nullable();
            $table->timestamps();
            $table->unique(['repository_id', 'pr_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_requests');
    }
};

