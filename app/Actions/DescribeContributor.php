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
     * The endpoints a source is asked about a contributor with.
     *
     * @return list<string>
     */
    public static function endpoints(MetadataSource $source): array
    {
        return $source === MetadataSource::LastFm ? DescribeRecording::LASTFM_ENDPOINTS : ['artist'];
    }

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
     * when the source has no identifier to ask with, or nothing is due.
     */
    public function handle(Contributor $contributor, MetadataSource $source): ?EnrichmentStatus
    {
        if ($source === MetadataSource::LastFm) {
            return $this->lastFm->enabled() ? $this->askLastFm($contributor) : null;
        }

        if ($source !== MetadataSource::MusicBrainz || $contributor->mbid === null) {
            return null;
        }

        $payload = $this->musicBrainz->artist($contributor->mbid);
        $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
        Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, $source, 'artist', $status, $payload);

        return $status;
    }

    /**
     * Asks each Last.fm endpoint that is due, by name: done when one of them
     * answered.
     */
    private function askLastFm(Contributor $contributor): ?EnrichmentStatus
    {
        $calls = [
            Enrichment::INFO => fn (): ?array => $this->lastFm->artistInfo($contributor->name),
            'top_tags' => fn (): ?array => $this->lastFm->artistTopTags($contributor->name),
            'similar' => fn (): ?array => $this->lastFm->artistSimilar($contributor->name),
        ];
        $statuses = [];

        foreach ($calls as $endpoint => $call) {
            if (! Enrichment::isDue(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, $endpoint)) {
                continue;
            }

            $payload = $call();
            $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
            Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, $endpoint, $status, $payload);
            $statuses[] = $status;
        }

        if ($statuses === []) {
            return null;
        }

        return in_array(EnrichmentStatus::Done, $statuses, true) ? EnrichmentStatus::Done : EnrichmentStatus::NotFound;
    }
}
