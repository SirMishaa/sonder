<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\SampledTrackData;
use App\Data\TrackData;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\YouTubeMusicAccount;

/**
 * Picks random tracks from the account's own library, optionally avoiding
 * everything already in one playlist.
 *
 * This feeds the front-end fixtures for features that are not built yet
 * (discovery, suggestions): the interface can render real artwork and titles
 * without pretending the recommendation logic exists.
 */
final readonly class SampleLibraryTracks
{
    /**
     * @return array<int, SampledTrackData>
     */
    public function handle(YouTubeMusicAccount $account, int $count, ?Playlist $excluding = null): array
    {
        $query = Track::query()
            ->with('playlist:id,youtube_playlist_id,title')
            ->whereNotNull('youtube_video_id')
            ->whereNotNull('thumbnail_url')
            ->whereHas('playlist', fn ($playlists) => $playlists
                ->where('youtube_music_account_id', $account->id)
                ->whereNull('removed_at'));

        if ($excluding !== null) {
            $query->where('playlist_id', '!=', $excluding->id)
                ->whereNotIn('youtube_video_id', $excluding->tracks()->whereNotNull('youtube_video_id')->select('youtube_video_id'));
        }

        return $query->inRandomOrder()
            ->limit($count * 3)
            ->get()
            ->unique('youtube_video_id')
            ->take($count)
            ->map(fn (Track $track): SampledTrackData => new SampledTrackData(
                track: TrackData::fromModel($track),
                playlistId: $track->playlist->youtube_playlist_id,
                playlistTitle: $track->playlist->title,
            ))
            ->values()
            ->all();
    }
}
