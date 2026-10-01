<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;

final readonly class DescribeContributor
{
    public function __construct(private MusicBrainzGateway $musicBrainz) {}

    /**
     * Fetches and stores what one source says about the contributor. Null
     * when the source has no identifier to ask with.
     */
    public function handle(Contributor $contributor, MetadataSource $source): ?EnrichmentStatus
    {
        if ($source !== MetadataSource::MusicBrainz || $contributor->mbid === null) {
            return null;
        }

        $payload = $this->musicBrainz->artist($contributor->mbid);
        $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
        Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, $source, 'artist', $status, $payload);

        return $status;
    }
}
