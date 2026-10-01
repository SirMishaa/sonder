<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use App\Enums\ArtistRole;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Exceptions\Providers\CredentialsRejected;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemoteAccount;
use App\Services\Music\Data\RemoteAlbum;
use App\Services\Music\Data\RemoteArtist;
use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\Data\RemotePlaylistSummary;
use App\Services\Music\Data\RemoteTrack;

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
     * @throws CredentialsRejected Without a channel id there is no account to act for.
     */
    public function account(object $payload): RemoteAccount
    {
        $channelId = $this->string($payload, 'channelId')
            ?? throw new CredentialsRejected(Provider::YouTubeMusic, 'the account has no channel id');

        return new RemoteAccount(
            ref: new ProviderRef(Provider::YouTubeMusic, $channelId),
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
        $videoType = $this->string($payload, 'videoType');

        if ($videoType === self::PODCAST_EPISODE) {
            return null;
        }

        $title = $this->string($payload, 'title') ?? 'Untitled track';
        $videoId = $this->string($payload, 'videoId');
        $album = $payload->album ?? null;

        return new RemoteTrack(
            ref: $videoId === null ? null : new ProviderRef(Provider::YouTubeMusic, $videoId),
            title: $title,
            artists: $this->artists($payload, $title),
            album: is_object($album) && ($albumTitle = $this->string($album, 'name')) !== null
                ? new RemoteAlbum($this->ref($album, 'id'), $albumTitle, null)
                : null,
            durationSeconds: is_int($payload->duration_seconds ?? null) ? $payload->duration_seconds : null,
            isrc: null,
            kind: $videoType === null || in_array($videoType, self::AUDIO_TYPES, true) ? SourceKind::Audio : SourceKind::Video,
            isExplicit: (bool) ($payload->isExplicit ?? false),
            isAvailable: (bool) ($payload->isAvailable ?? true),
            thumbnailUrl: $this->thumbnail($payload),
        );
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
    private function artists(object $payload, string $title): array
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

        return $artists === [] ? [new RemoteArtist(null, 'Unknown artist', ArtistRole::Main)] : $artists;
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
