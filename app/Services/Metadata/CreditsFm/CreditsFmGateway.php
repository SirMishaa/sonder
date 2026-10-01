<?php

declare(strict_types=1);

namespace App\Services\Metadata\CreditsFm;

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Raw calls to credits.fm's public read API.
 */
interface CreditsFmGateway
{
    /**
     * At most 50 queries. credits.fm answers even nonsense with an ISRC:
     * callers must verify it.
     *
     * @param  list<TrackQuery>  $queries
     * @return list<string|null> one ISRC (or null) per query, in order
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function resolveBatch(array $queries): array;

    /**
     * @return array<string, mixed>|null null when credits.fm does not know the ISRC
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function isrc(string $isrc): ?array;
}
