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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('original_filename', 255);
            $table->string('input_path', 500);
            $table->string('output_path', 500)->nullable();
            $table->unsignedBigInteger('original_size');
            $table->unsignedBigInteger('output_size')->nullable();
            $table->decimal('duration', 10, 2)->nullable();
            $table->unsignedInteger('source_width')->nullable();
            $table->unsignedInteger('source_height')->nullable();
            $table->string('source_format', 50)->nullable();
            $table->string('source_codec', 50)->nullable();
            $table->string('source_fps', 20)->nullable();
            $table->boolean('has_audio')->default(true);
            $table->unsignedBigInteger('target_size')->nullable();
            $table->string('target_resolution', 20)->default('original');
            $table->string('encoding_preset', 20)->default('medium');
            $table->string('status', 30)->default('uploaded')->index();
            $table->string('processing_stage', 50)->nullable();
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};

