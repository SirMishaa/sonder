<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Music\Data\RemoteTrack;
use App\Services\Thumbnails\ThumbnailProxy;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A catalog track: every field of TrackData (so the player takes it as is),
 * plus the catalog ids and whether the user's library holds it.
 */
#[TypeScript]
final class CatalogTrackData extends Data
{
    /**
     * @param  list<string>  $genres  strongest first, at most two
     */
    public function __construct(
        public ?string $videoId,
        public string $title,
        public string $artists,
        public ?string $album,
        public ?string $duration,
        public ?int $durationSeconds,
        public ?string $thumbnailUrl,
        public bool $isExplicit,
        public bool $isAvailable,
        public array $genres,
        public ?string $artistId,
        public ?string $albumId,
        public ?int $year,
        public ?int $plays,
        public bool $inLibrary,
    ) {}

    /**
     * @param  list<string>  $genres
     */
    public static function fromRemote(RemoteTrack $track, array $genres, bool $inLibrary, ?int $plays = null): self
    {
        return new self(
            videoId: $track->ref?->externalId,
            title: $track->title,
            artists: $track->artistNames(),
            album: $track->album?->title,
            duration: $track->durationSeconds === null ? null : self::clock($track->durationSeconds),
            durationSeconds: $track->durationSeconds,
            thumbnailUrl: ThumbnailProxy::url($track->thumbnailUrl),
            isExplicit: $track->isExplicit,
            isAvailable: $track->isAvailable,
            genres: $genres,
            artistId: ($track->artists[0] ?? null)?->ref?->externalId,
            albumId: $track->album?->ref?->externalId,
            year: $track->album?->year,
            plays: $plays,
            inLibrary: $inLibrary,
        );
    }

    /**
     * "3:35", or "1:02:03" past an hour, as the library shows it.
     */
    private static function clock(int $seconds): string
    {
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
    }
}
