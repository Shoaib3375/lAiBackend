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
        Schema::create('review_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->unsignedInteger('line_number');
            $table->enum('severity', ['error', 'warning', 'info']);
            $table->text('body');
            $table->bigInteger('github_comment_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('review_comments');
    }
};

