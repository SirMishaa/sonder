<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Services\Metadata\LastFm\LastFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;

final readonly class DescribeContributor
{
    public function __construct(
        private MusicBrainzGateway $musicBrainz,
        private LastFmGateway $lastFm,
    ) {}

    /**
     * The sources able to describe this contributor that never did:
     * MusicBrainz needs an MBID, Last.fm a key.
     *
     * @return list<MetadataSource>
     */
    public function undescribedSources(Contributor $contributor): array
    {
        $able = [];

        if ($contributor->mbid !== null) {
            $able[] = MetadataSource::MusicBrainz;
        }

        if ($this->lastFm->enabled()) {
            $able[] = MetadataSource::LastFm;
        }

        return array_values(array_filter($able, fn (MetadataSource $source): bool => Enrichment::query()
            ->where('subject_type', Enrichment::CONTRIBUTOR)
            ->where('subject_key', $contributor->id)
            ->where('source', $source)
            ->doesntExist()));
    }

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
