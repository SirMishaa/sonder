<?php

declare(strict_types=1);

namespace App\Services\YouTubeMusic;

use App\Data\AccountData;
use App\Data\PlaylistData;
use App\Data\PlaylistSummaryData;
use App\Data\TrackData;

/**
 * Turns the untyped objects returned by `ytmusicapi/ytmusicapi` into typed
 * data.
 *
 * Kept apart from the network client on purpose. These payloads are built by
 * walking a renderer tree that Google reshapes without notice, so this is the
 * part most likely to break and the part most worth testing. Every field is
 * treated as optional: a missing one means YouTube changed something, not that
 * the caller did anything wrong.
 */
final readonly class PayloadMapper
{
    private const int THUMBNAIL_WIDTH = 240;

    public function account(object $payload): AccountData
    {
        return new AccountData(
            name: $this->string($payload, 'name') ?? 'Unknown account',
            channelId: $this->string($payload, 'channelId'),
            thumbnailUrl: $this->thumbnail($payload),
            isPremium: (bool) ($payload->is_premium ?? false),
        );
    }

    public function playlistSummary(object $payload): PlaylistSummaryData
    {
        return new PlaylistSummaryData(
            id: $this->string($payload, 'playlistId') ?? '',
            title: $this->string($payload, 'title') ?? 'Untitled playlist',
            description: $this->string($payload, 'description'),
            trackCount: $this->trackCount($payload),
            thumbnailUrl: $this->thumbnail($payload),
            author: $this->author($payload),
        );
    }

    public function playlist(object $payload, string $playlistId, ?int $fallbackTrackCount = null): PlaylistData
    {
        $tracks = is_array($payload->tracks ?? null) ? $payload->tracks : [];

        $mapped = [];

        foreach ($tracks as $track) {
            if (is_object($track)) {
                $mapped[] = $this->track($track);
            }
        }

        return new PlaylistData(
            id: $playlistId,
            title: $this->string($payload, 'title') ?? 'Untitled playlist',
            description: $this->string($payload, 'description'),
            trackCount: $this->trackCount($payload) ?? $fallbackTrackCount ?? count($mapped),
            duration: $this->string($payload, 'duration'),
            thumbnailUrl: $this->thumbnail($payload),
            author: $this->author($payload),
            tracks: $mapped,
        );
    }

    public function track(object $payload): TrackData
    {
        $album = $payload->album ?? null;

        return new TrackData(
            videoId: $this->string($payload, 'videoId'),
            title: $this->string($payload, 'title') ?? 'Untitled track',
            artists: $this->artists($payload),
            album: is_object($album) ? $this->string($album, 'name') : null,
            duration: $this->string($payload, 'duration'),
            durationSeconds: is_int($payload->duration_seconds ?? null) ? $payload->duration_seconds : null,
            thumbnailUrl: $this->thumbnail($payload),
            isExplicit: (bool) ($payload->isExplicit ?? false),
            isAvailable: (bool) ($payload->isAvailable ?? true),
        );
    }

    /**
     * The count is scraped out of a subtitle string and silently falls back to
     * zero, so zero means "unknown" rather than "empty". The distinction
     * matters: the cache key is derived from this value, and treating an
     * unknown count as a real one would pin a stale playlist in the cache.
     */
    private function trackCount(object $payload): ?int
    {
        $count = $payload->trackCount ?? $payload->count ?? null;

        if (is_string($count) && is_numeric($count)) {
            $count = (int) $count;
        }

        return is_int($count) && $count > 0 ? $count : null;
    }

    private function artists(object $payload): string
    {
        $artists = $payload->artists ?? null;

        if (! is_array($artists)) {
            return 'Unknown artist';
        }

        $names = [];

        foreach ($artists as $artist) {
            if (is_object($artist) && ($name = $this->string($artist, 'name')) !== null) {
                $names[] = $name;
            }
        }

        return $names === [] ? 'Unknown artist' : implode(', ', $names);
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
     * Picks the smallest thumbnail that is still large enough to render
     * sharply, falling back to the largest available.
     */
    private function thumbnail(object $payload): ?string
    {
        $thumbnails = $payload->thumbnails ?? null;

        if (! is_array($thumbnails)) {
            return null;
        }

        $candidates = [];

        foreach ($thumbnails as $thumbnail) {
            if (is_object($thumbnail) && $this->string($thumbnail, 'url') !== null) {
                $candidates[] = $thumbnail;
            }
        }

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

    private function string(object $payload, string $key): ?string
    {
        $value = $payload->{$key} ?? null;

        if (! is_string($value) || mb_trim($value) === '') {
            return null;
        }

        return $value;
    }
}
