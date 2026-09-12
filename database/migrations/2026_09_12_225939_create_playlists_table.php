<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('youtube_music_account_id')->constrained('youtube_music_accounts')->cascadeOnDelete();
            $table->string('youtube_playlist_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('track_count')->nullable();
            $table->string('duration')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('last_synced_at');
            $table->timestamps();

            $table->unique(['youtube_music_account_id', 'youtube_playlist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlists');
    }
};
