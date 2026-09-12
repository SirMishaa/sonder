<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('playlist_id')->constrained()->cascadeOnDelete();
            $table->string('youtube_video_id')->nullable()->index();
            $table->string('title');
            $table->string('artists');
            $table->string('album')->nullable();
            $table->string('duration')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->boolean('is_explicit')->default(false);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['playlist_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
