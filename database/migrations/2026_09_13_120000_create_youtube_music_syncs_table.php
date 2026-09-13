<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_music_syncs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('youtube_music_account_id')->constrained('youtube_music_accounts')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_playlists')->nullable();
            $table->unsignedInteger('synced_playlists')->default(0);
            $table->string('current_playlist_title')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['youtube_music_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_music_syncs');
    }
};
