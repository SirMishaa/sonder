<?php

declare(strict_types=1);

namespace App\Services\Metadata\MusicBrainz;

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Raw calls to the MusicBrainz web service (ws/2, JSON). Every method may
 * throw MetadataSourceRateLimited or MetadataSourceUnavailable.
 */
interface MusicBrainzGateway
{
    /**
     * Recordings carrying this ISRC, with their artists. Null when unknown.
     *
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function isrc(string $isrc): ?array;

    /**
     * One recording with genres, tags, artists and ISRCs. Null when unknown.
     *
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function recording(string $mbid): ?array;

    /**
     * @return array<string, mixed>
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function searchRecordings(TrackQuery $query): array;

    /**
     * One artist with genres and tags. Null when unknown.
     *
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function artist(string $mbid): ?array;
}
