<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use App\Enums\ArtistRole;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Services\Music\Data\CatalogAlbum;
use App\Services\Music\Data\CatalogAlbumSummary;
use App\Services\Music\Data\CatalogArtist;
use App\Services\Music\Data\CatalogArtistSummary;
use App\Services\Music\Data\CatalogPlaylistSummary;
use App\Services\Music\Data\CatalogResults;
use App\Services\Music\Data\CatalogVideo;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemoteAccount;
use App\Services\Music\Data\RemoteAlbum;
use App\Services\Music\Data\RemoteArtist;
use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\Data\RemotePlaylistSummary;
use App\Services\Music\Data\RemoteTrack;
use Illuminate\Support\Str;

/**
 * Turns ytmusicapi's untyped payloads into Sonder's exchange data.
 *
 * These payloads come from a renderer tree Google reshapes without notice,
 * so every field is treated as optional: a missing one means YouTube changed
 * something, not that the caller did anything wrong.
 */
final readonly class YouTubeMusicMapper
{
    private const int THUMBNAIL_WIDTH = 240;

    /** Video types that are a recording rather than a video of it. */
    private const array AUDIO_TYPES = ['MUSIC_VIDEO_TYPE_ATV', 'MUSIC_VIDEO_TYPE_PRIVATELY_OWNED_TRACK'];

    private const string PODCAST_EPISODE = 'MUSIC_VIDEO_TYPE_PODCAST_EPISODE';

    /**
     * A signed-out session never gets here (ytmusicapi throws "Could not find
     * account information."), so a missing channel id only means the account
     * has no YouTube channel.
     */
    public function account(object $payload): RemoteAccount
    {
        return new RemoteAccount(
            ref: $this->ref($payload, 'channelId'),
            displayName: $this->string($payload, 'name') ?? 'Unknown account',
        );
    }

    /**
     * Null for an entry YouTube Music lists without an id.
     */
    public function playlistSummary(object $payload): ?RemotePlaylistSummary
    {
        $id = $this->string($payload, 'playlistId');

        return $id === null ? null : $this->summary($payload, $id, $this->trackCount($payload));
    }

    public function playlist(object $payload, string $externalId, ?int $trackCountHint = null): RemotePlaylist
    {
        $tracks = [];

        foreach (is_array($payload->tracks ?? null) ? $payload->tracks : [] as $track) {
            if (is_object($track) && ($mapped = $this->track($track)) !== null) {
                $tracks[] = $mapped;
            }
        }

        $trackCount = $this->trackCount($payload) ?? $trackCountHint ?? count($tracks);

        return new RemotePlaylist($this->summary($payload, $externalId !== '' ? $externalId : 'unknown', $trackCount), $tracks);
    }

    /**
     * Null for podcast episodes, which are not music.
     */
    public function track(object $payload): ?RemoteTrack
    {
        return $this->mapTrack($payload, true);
    }

    /**
     * Catalog hits keep an empty artist list when YouTube credits none, so the
     * caller can tell "unknown" from a real artist named so.
     *
     * @param  list<mixed>  $payload
     */
    public function searchResults(array $payload): CatalogResults
    {
        $topArtist = null;
        $tracks = $albums = $artists = $playlists = [];
        $inTopCard = false;

        foreach ($payload as $hit) {
            if (! is_object($hit)) {
                $inTopCard = false;

                continue;
            }

            $type = $this->string($hit, 'resultType');
            $wasInTopCard = $inTopCard;
            $inTopCard = false;

            switch ($type) {
                case 'artist':
                    if ($this->string($hit, 'category') === 'Top result') {
                        $topArtist ??= $this->topResultArtist($hit);
                        $inTopCard = $topArtist !== null;

                        break;
                    }

                    if (($artist = $this->artistSummary($hit, $this->string($hit, 'artist') ?? $this->string($hit, 'title'))) !== null) {
                        $artists[] = $artist;
                    }

                    break;
                case 'song':
                    $inTopCard = $wasInTopCard;

                    if (($track = $this->mapTrack($hit, false)) !== null) {
                        $tracks[] = $wasInTopCard && $topArtist !== null && $track->artists === []
                            ? $this->creditedTo($track, $topArtist)
                            : $track;
                    }

                    break;
                case 'album':
                    if (($album = $this->albumSummary($hit)) !== null) {
                        $albums[] = $album;
                    }

                    break;
                case 'playlist':
                    if (($playlist = $this->playlistHit($hit)) !== null) {
                        $playlists[] = $playlist;
                    }

                    break;
            }
        }

        return new CatalogResults($topArtist, $tracks, $albums, $artists, $playlists);
    }

    public function artist(object $payload, string $channelId): CatalogArtist
    {
        $summary = new CatalogArtistSummary(
            ref: $this->idRef($channelId),
            name: $this->string($payload, 'name') ?? 'Unknown artist',
            thumbnailUrl: $this->thumbnail($payload),
            subscribers: $this->string($payload, 'subscribers'),
        );

        $topTracks = [];

        foreach ($this->results($payload, 'songs') as $track) {
            if (($mapped = $this->mapTrack($track, false)) !== null) {
                $topTracks[] = $mapped;
            }
        }

        $albums = $singles = $videos = $related = [];

        foreach ($this->results($payload, 'albums') as $album) {
            if (($mapped = $this->albumSummary($album, 'Album')) !== null) {
                $albums[] = $mapped;
            }
        }

        foreach ($this->results($payload, 'singles') as $single) {
            if (($mapped = $this->albumSummary($single, 'Single')) !== null) {
                $singles[] = $mapped;
            }
        }

        foreach ($this->results($payload, 'videos') as $video) {
            $videoId = $this->string($video, 'videoId');

            if ($videoId !== null) {
                $videos[] = new CatalogVideo(
                    ref: new ProviderRef(Provider::YouTubeMusic, $videoId),
                    title: $this->string($video, 'title') ?? 'Untitled video',
                    thumbnailUrl: $this->thumbnail($video),
                    views: $this->count($this->string($video, 'views')),
                );
            }
        }

        foreach ($this->results($payload, 'related') as $artist) {
            if (($mapped = $this->artistSummary($artist, $this->string($artist, 'title'))) !== null) {
                $related[] = $mapped;
            }
        }

        return new CatalogArtist(
            summary: $summary,
            monthlyListeners: $this->string($payload, 'monthlyListeners'),
            views: $this->count($this->string($payload, 'views')),
            description: $this->string($payload, 'description'),
            topTracks: $topTracks,
            albums: $albums,
            singles: $singles,
            videos: $videos,
            related: $related,
        );
    }

    public function album(object $payload, string $browseId): CatalogAlbum
    {
        $ref = $this->idRef($browseId);
        $title = $this->string($payload, 'title') ?? 'Untitled album';
        $albumRef = new RemoteAlbum($ref, $title, null);

        $tracks = [];
        $plays = [];

        foreach (is_array($payload->tracks ?? null) ? $payload->tracks : [] as $track) {
            if (! is_object($track) || ($mapped = $this->mapTrack($track, false, $albumRef)) === null) {
                continue;
            }

            $tracks[] = $mapped;

            if ($mapped->ref !== null && ($count = $this->count($this->string($track, 'playCount') ?? $this->string($track, 'views'))) !== null) {
                $plays[$mapped->ref->externalId] = $count;
            }
        }

        $trackCount = $payload->trackCount ?? null;
        $duration = $payload->duration_seconds ?? null;

        return new CatalogAlbum(
            summary: new CatalogAlbumSummary(
                ref: $ref,
                title: $title,
                type: $this->string($payload, 'type') ?? 'Album',
                year: $this->year($payload),
                artists: $this->artists($payload, $title, false),
                thumbnailUrl: $this->thumbnail($payload),
                isExplicit: (bool) ($payload->isExplicit ?? false),
            ),
            description: $this->string($payload, 'description'),
            trackCount: is_int($trackCount) && $trackCount > 0 ? $trackCount : count($tracks),
            durationSeconds: is_int($duration) ? $duration : null,
            tracks: $tracks,
            playsByVideoId: $plays,
        );
    }

    private function mapTrack(object $payload, bool $unknownArtistFallback, ?RemoteAlbum $album = null): ?RemoteTrack
    {
        $videoType = $this->string($payload, 'videoType');

        if ($videoType === self::PODCAST_EPISODE) {
            return null;
        }

        $title = $this->string($payload, 'title') ?? 'Untitled track';
        $videoId = $this->string($payload, 'videoId');
        $rawAlbum = $payload->album ?? null;

        return new RemoteTrack(
            ref: $videoId === null ? null : new ProviderRef(Provider::YouTubeMusic, $videoId),
            title: $title,
            artists: $this->artists($payload, $title, $unknownArtistFallback),
            album: $album ?? (is_object($rawAlbum) && ($albumTitle = $this->string($rawAlbum, 'name')) !== null
                ? new RemoteAlbum($this->ref($rawAlbum, 'id'), $albumTitle, null)
                : null),
            durationSeconds: is_int($payload->duration_seconds ?? null) ? $payload->duration_seconds : null,
            isrc: null,
            kind: $videoType === null || in_array($videoType, self::AUDIO_TYPES, true) ? SourceKind::Audio : SourceKind::Video,
            isExplicit: (bool) ($payload->isExplicit ?? false),
            isAvailable: (bool) ($payload->isAvailable ?? true),
            thumbnailUrl: $this->thumbnail($payload),
        );
    }

    /**
     * The "Top result" card carries no browse id: the artist hides in its artists list.
     */
    private function topResultArtist(object $hit): ?CatalogArtistSummary
    {
        $artists = is_array($hit->artists ?? null) ? $hit->artists : [];
        $first = $artists[0] ?? null;

        if (! is_object($first) || ($ref = $this->ref($first, 'id')) === null || ($name = $this->string($first, 'name')) === null) {
            return null;
        }

        return new CatalogArtistSummary($ref, $name, $this->thumbnail($hit), $this->string($hit, 'subscribers'));
    }

    /**
     * The search "Top result" card lists its songs without artists: they belong to the card's artist.
     */
    private function creditedTo(RemoteTrack $track, CatalogArtistSummary $artist): RemoteTrack
    {
        return new RemoteTrack(
            ref: $track->ref,
            title: $track->title,
            artists: [new RemoteArtist($artist->ref, $artist->name, ArtistRole::Main)],
            album: $track->album,
            durationSeconds: $track->durationSeconds,
            isrc: $track->isrc,
            kind: $track->kind,
            isExplicit: $track->isExplicit,
            isAvailable: $track->isAvailable,
            thumbnailUrl: $track->thumbnailUrl,
        );
    }

    private function artistSummary(object $hit, ?string $name): ?CatalogArtistSummary
    {
        $ref = $this->ref($hit, 'browseId');

        return $ref === null || $name === null
            ? null
            : new CatalogArtistSummary($ref, $name, $this->thumbnail($hit), $this->string($hit, 'subscribers'));
    }

    private function albumSummary(object $hit, string $defaultType = 'Album'): ?CatalogAlbumSummary
    {
        $ref = $this->ref($hit, 'browseId');

        if ($ref === null) {
            return null;
        }

        $title = $this->string($hit, 'title') ?? 'Untitled album';

        return new CatalogAlbumSummary(
            ref: $ref,
            title: $title,
            type: $this->string($hit, 'type') ?? $defaultType,
            year: $this->year($hit),
            artists: $this->artists($hit, $title, false),
            thumbnailUrl: $this->thumbnail($hit),
            isExplicit: (bool) ($hit->isExplicit ?? false),
        );
    }

    /**
     * Search lists playlists by their "VL" browse id; the playlist id is what follows.
     */
    private function playlistHit(object $hit): ?CatalogPlaylistSummary
    {
        $id = $this->string($hit, 'playlistId') ?? $this->string($hit, 'browseId');

        if ($id === null) {
            return null;
        }

        $id = Str::chopStart($id, 'VL');
        $author = $hit->author ?? null;

        return new CatalogPlaylistSummary(
            ref: $this->idRef($id),
            title: $this->string($hit, 'title') ?? 'Untitled playlist',
            author: is_string($author) && mb_trim($author) !== '' ? $author : $this->author($hit),
            trackCount: $this->count($this->string($hit, 'itemCount')),
            thumbnailUrl: $this->thumbnail($hit),
        );
    }

    /**
     * @return list<object>
     */
    private function results(object $payload, string $section): array
    {
        $shelf = $payload->{$section} ?? null;
        $results = is_object($shelf) && is_array($shelf->results ?? null) ? $shelf->results : [];

        return array_values(array_filter($results, is_object(...)));
    }

    private function year(object $payload): ?int
    {
        $year = $payload->year ?? null;

        return is_numeric($year) && (int) $year > 0 ? (int) $year : null;
    }

    /**
     * Reads counts such as "36M plays", "312K" or "8,401,443,283 views".
     */
    private function count(?string $text): ?int
    {
        if ($text === null || preg_match('/(\d(?:[\d.,\x{00A0}\x{202F} ]*\d)?)\s*(?:([KMB])(?![A-Za-z]))?/u', $text, $match) !== 1) {
            return null;
        }

        $multiplier = match (mb_strtoupper($match[2] ?? '')) {
            'K' => 1_000,
            'M' => 1_000_000,
            'B' => 1_000_000_000,
            default => 1,
        };

        if ($multiplier === 1) {
            $digits = preg_replace('/\D/u', '', $match[1]);

            return $digits === null || $digits === '' ? null : (int) $digits;
        }

        return (int) round((float) str_replace(',', '.', mb_trim($match[1])) * $multiplier);
    }

    /**
     * @param  non-empty-string  $id
     */
    private function summary(object $payload, string $id, ?int $trackCount): RemotePlaylistSummary
    {
        return new RemotePlaylistSummary(
            ref: new ProviderRef(Provider::YouTubeMusic, $id),
            title: $this->string($payload, 'title') ?? 'Untitled playlist',
            description: $this->string($payload, 'description'),
            trackCount: $trackCount,
            thumbnailUrl: $this->thumbnail($payload),
            author: $this->author($payload),
        );
    }

    /**
     * Artists named in a "feat." / "ft." clause of the title are featured;
     * YouTube Music lists them alongside the main artists without telling.
     *
     * @return list<RemoteArtist>
     */
    private function artists(object $payload, string $title, bool $unknownFallback = true): array
    {
        $featuring = preg_match('/\b(?:feat\.?|ft\.?|featuring)\s+([^)\]]+)/iu', $title, $match) === 1
            ? mb_strtolower($match[1])
            : '';

        $artists = [];

        foreach (is_array($payload->artists ?? null) ? $payload->artists : [] as $artist) {
            if (! is_object($artist) || ($name = $this->string($artist, 'name')) === null) {
                continue;
            }

            $role = $featuring !== '' && str_contains($featuring, mb_strtolower($name)) ? ArtistRole::Featured : ArtistRole::Main;
            $artists[] = new RemoteArtist($this->ref($artist, 'id'), $name, $role);
        }

        return $artists === [] && $unknownFallback ? [new RemoteArtist(null, 'Unknown artist', ArtistRole::Main)] : $artists;
    }

    /**
     * The count is scraped out of a subtitle string and silently defaults to
     * zero, so zero means unknown rather than empty.
     */
    private function trackCount(object $payload): ?int
    {
        $count = $payload->trackCount ?? $payload->count ?? null;

        if (is_string($count) && is_numeric($count)) {
            $count = (int) $count;
        }

        return is_int($count) && $count > 0 ? $count : null;
    }

    private function author(object $payload): ?string
    {
        $author = $payload->author ?? null;

        if (is_object($author)) {
            return $this->string($author, 'name');
        }

        if (is_array($author) && isset($author[0]) && is_object($author[0])) {
            return $this->string($author[0], 'name');
        }

        return null;
    }

    /**
     * The smallest raw thumbnail still wide enough to render sharply, or the
     * largest one available.
     */
    private function thumbnail(object $payload): ?string
    {
        $candidates = array_values(array_filter(
            is_array($payload->thumbnails ?? null) ? $payload->thumbnails : [],
            fn (mixed $thumbnail): bool => is_object($thumbnail) && $this->string($thumbnail, 'url') !== null,
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (object $a, object $b): int => $this->width($a) <=> $this->width($b));

        foreach ($candidates as $candidate) {
            if ($this->width($candidate) >= self::THUMBNAIL_WIDTH) {
                return $this->string($candidate, 'url');
            }
        }

        return $this->string($candidates[count($candidates) - 1], 'url');
    }

    private function width(object $thumbnail): int
    {
        $width = $thumbnail->width ?? 0;

        return is_int($width) ? $width : 0;
    }

    /**
     * An empty id only comes from a caller bug; it is kept out of the exchange data.
     */
    private function idRef(string $id): ProviderRef
    {
        return new ProviderRef(Provider::YouTubeMusic, $id !== '' ? $id : 'unknown');
    }

    private function ref(object $payload, string $key): ?ProviderRef
    {
        $id = $this->string($payload, $key);

        return $id === null ? null : new ProviderRef(Provider::YouTubeMusic, $id);
    }

    /**
     * @return non-empty-string|null
     */
    private function string(object $payload, string $key): ?string
    {
        $value = $payload->{$key} ?? null;

        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }
}
