<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ArtistTallyData;
use App\Data\LibraryStatsData;
use App\Models\Track;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Str;

/**
 * Computes what can honestly be said about a library from its synced
 * contents alone. Listening history is not available, so nothing here claims
 * to know what was played. A video saved in several playlists counts once.
 */
final readonly class SummarizeLibrary
{
    private const int TOP_ARTISTS = 5;

    public function handle(YouTubeMusicAccount $account): LibraryStatsData
    {
        $tracks = Track::query()
            ->whereHas('playlist', fn ($playlists) => $playlists
                ->where('youtube_music_account_id', $account->id)
                ->whereNull('removed_at'))
            ->get(['youtube_video_id', 'artists', 'duration_seconds'])
            ->unique(fn (Track $track): string => $track->youtube_video_id ?? spl_object_hash($track));

        $leadArtists = $tracks
            ->map(fn (Track $track): string => Str::of($track->artists)->before(',')->trim()->value())
            ->reject(fn (string $artist): bool => $artist === '' || $artist === 'Unknown artist');

        $topArtists = $leadArtists
            ->countBy()
            ->sortDesc()
            ->take(self::TOP_ARTISTS)
            ->map(fn (int $count, string $name): ArtistTallyData => new ArtistTallyData($name, $count))
            ->values()
            ->all();

        return new LibraryStatsData(
            playlistCount: $account->playlists()->whereNull('removed_at')->count(),
            trackCount: $tracks->count(),
            artistCount: $leadArtists->unique()->count(),
            totalHours: intdiv($tracks->sum(fn (Track $track): int => $track->duration_seconds ?? 0), 3600),
            topArtists: $topArtists,
        );
    }
}
