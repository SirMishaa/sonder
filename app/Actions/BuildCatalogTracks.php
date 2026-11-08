<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\CatalogTrackData;
use App\Models\User;
use App\Services\Music\Data\RemoteTrack;

/**
 * Turns catalog tracks into page data for one user: library marks, genres and plays.
 */
final readonly class BuildCatalogTracks
{
    public function __construct(
        private MatchLibraryTracks $matchLibraryTracks,
        private TrackGenres $trackGenres,
    ) {}

    /**
     * @param  list<RemoteTrack>  $tracks
     * @param  array<string, int>  $playsByVideoId
     * @return list<CatalogTrackData>
     */
    public function handle(User $user, array $tracks, array $playsByVideoId = []): array
    {
        $inLibrary = $this->matchLibraryTracks->handle($user, $tracks);
        $genres = $this->trackGenres->handle(array_values(array_filter(
            array_map(fn (RemoteTrack $track): ?string => $track->ref?->externalId, $tracks),
            is_string(...),
        )));

        return array_map(
            fn (RemoteTrack $track, int $index): CatalogTrackData => CatalogTrackData::fromRemote(
                $track,
                $genres[$track->ref->externalId ?? ''] ?? [],
                $inLibrary[$index] ?? false,
                $playsByVideoId[$track->ref->externalId ?? ''] ?? null,
            ),
            $tracks,
            array_keys($tracks),
        );
    }
}
