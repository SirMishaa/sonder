<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use App\Services\YouTubeMusic\Client;
use Illuminate\Support\Facades\DB;

final readonly class SyncPlaylistsFromYouTubeMusicAction
{
    public function __construct(private Client $client) {}

    public function handle(YouTubeMusicAccount $account): void
    {
        $playlistSummaries = $this->client->playlists($account->cookie);

        DB::transaction(function () use ($account, $playlistSummaries): void {
            foreach ($playlistSummaries as $summary) {
                $playlist = Playlist::updateOrCreate(
                    [
                        'youtube_music_account_id' => $account->id,
                        'youtube_playlist_id' => $summary->id,
                    ],
                    [
                        'title' => $summary->title,
                        'description' => $summary->description,
                        'track_count' => $summary->trackCount,
                        'thumbnail_url' => $summary->thumbnailUrl,
                        'author' => $summary->author,
                        'last_synced_at' => now(),
                    ]
                );

                $playlistData = $this->client->playlist(
                    $account->cookie,
                    $summary->id,
                    $summary->trackCount
                );

                $playlist->update([
                    'duration' => $playlistData->duration,
                ]);

                $playlist->tracks()->delete();

                foreach ($playlistData->tracks as $position => $track) {
                    $playlist->tracks()->create([
                        'youtube_video_id' => $track->videoId,
                        'title' => $track->title,
                        'artists' => $track->artists,
                        'album' => $track->album,
                        'duration' => $track->duration,
                        'duration_seconds' => $track->durationSeconds,
                        'thumbnail_url' => $track->thumbnailUrl,
                        'is_explicit' => $track->isExplicit,
                        'is_available' => $track->isAvailable,
                        'position' => $position,
                    ]);
                }
            }
        });
    }
}
