<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('youtube_video_id')->index();
            $table->string('title');
            $table->string('artists');
            $table->string('youtube_playlist_id')->nullable();
            $table->string('origin');
            $table->string('end_reason');
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->unsignedInteger('position_seconds');
            $table->unsignedInteger('listened_seconds');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listens');
    }
};
