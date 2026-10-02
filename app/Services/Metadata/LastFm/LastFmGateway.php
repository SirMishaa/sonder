<?php

declare(strict_types=1);

namespace App\Services\Metadata\LastFm;

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Raw calls to the Last.fm API (JSON, autocorrected). Every call may throw
 * MetadataSourceRateLimited or MetadataSourceUnavailable; null means Last.fm
 * does not know the subject.
 */
interface LastFmGateway
{
    /**
     * Whether an API key is configured. Without one, nothing asks Last.fm.
     */
    public function enabled(): bool;

    /**
     * Corrected title and artist, duration, listeners, play count.
     *
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function trackInfo(TrackQuery $query): ?array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function trackTopTags(TrackQuery $query): ?array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function trackSimilar(TrackQuery $query): ?array;

    /**
     * Listeners, play count, a few tags and similar artists.
     *
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function artistInfo(string $name): ?array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function artistTopTags(string $name): ?array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function artistSimilar(string $name): ?array;

    /**
     * The 200 most listened tracks, worldwide or in one country (its
     * English name, as `belgium`).
     *
     * @return array<string, mixed>
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function topTracks(?string $country): array;
}
