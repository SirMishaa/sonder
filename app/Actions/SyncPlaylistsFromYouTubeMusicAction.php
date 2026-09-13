<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use App\Services\YouTubeMusic\Client;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class SyncPlaylistsFromYouTubeMusicAction
{
    public function __construct(private Client $client) {}

    /**
     * @param  Closure(int $synced, int $total, ?Playlist $playlist): void|null  $onProgress
     *                                                                                        Called once with `(0, $total, null)` right after the playlist
     *                                                                                        list is fetched, then once per playlist right after it is fully
     *                                                                                        upserted (tracks included).
     */
    public function handle(YouTubeMusicAccount $account, ?Closure $onProgress = null): void
    {
        $playlistSummaries = $this->client->playlists($account->cookie);
        $total = count($playlistSummaries);

        $onProgress?->__invoke(0, $total, null);

        foreach ($playlistSummaries as $index => $summary) {
            $playlist = DB::transaction(function () use ($account, $summary): Playlist {
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

                return $playlist;
            });

            $onProgress?->__invoke($index + 1, $total, $playlist);
        }
    }
}
