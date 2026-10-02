<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\LastFm\LastFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;

final readonly class DescribeRecording
{
    /** @var list<string> */
    public const array LASTFM_ENDPOINTS = [Enrichment::INFO, 'top_tags', 'similar'];

    public function __construct(
        private CreditsFmGateway $creditsFm,
        private MusicBrainzGateway $musicBrainz,
        private LastFmGateway $lastFm,
    ) {}

    /**
     * The endpoints a source is asked about a recording with.
     *
     * @return list<string>
     */
    public static function endpoints(MetadataSource $source): array
    {
        return match ($source) {
            MetadataSource::CreditsFm => ['isrc'],
            MetadataSource::LastFm => self::LASTFM_ENDPOINTS,
            default => ['recording'],
        };
    }

    /**
     * The sources that describe recordings: Last.fm only with an API key.
     *
     * @return list<MetadataSource>
     */
    public function sources(): array
    {
        return $this->lastFm->enabled()
            ? [MetadataSource::CreditsFm, MetadataSource::MusicBrainz, MetadataSource::LastFm]
            : [MetadataSource::CreditsFm, MetadataSource::MusicBrainz];
    }

    /**
     * Fetches and stores what one source says about the recording. Null when
     * the source has no identifier to ask with, or nothing is due. Failures
     * propagate; Last.fm's answers stored before a failure are kept.
     */
    public function handle(Recording $recording, MetadataSource $source): ?EnrichmentStatus
    {
        if ($source === MetadataSource::LastFm) {
            return $this->lastFm->enabled() ? $this->askLastFm($recording) : null;
        }

        if ($source === MetadataSource::CreditsFm && $recording->isrc !== null) {
            $payload = $this->creditsFm->isrc($recording->isrc);
        } elseif ($source === MetadataSource::MusicBrainz && $recording->mbid !== null) {
            $payload = $this->musicBrainz->recording($recording->mbid);
        } else {
            return null;
        }

        $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
        Enrichment::store(Enrichment::RECORDING, $recording->id, $source, self::endpoints($source)[0], $status, $payload);

        return $status;
    }

    /**
     * Asks each Last.fm endpoint that is due, by title and artist: done when
     * one of them answered.
     */
    private function askLastFm(Recording $recording): ?EnrichmentStatus
    {
        $query = new TrackQuery($recording->title, $recording->artist_name);
        $calls = [
            Enrichment::INFO => fn (): ?array => $this->lastFm->trackInfo($query),
            'top_tags' => fn (): ?array => $this->lastFm->trackTopTags($query),
            'similar' => fn (): ?array => $this->lastFm->trackSimilar($query),
        ];
        $statuses = [];

        foreach ($calls as $endpoint => $call) {
            if (! Enrichment::isDue(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, $endpoint)) {
                continue;
            }

            $payload = $call();
            $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
            Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, $endpoint, $status, $payload);
            $statuses[] = $status;
        }

        if ($statuses === []) {
            return null;
        }

        return in_array(EnrichmentStatus::Done, $statuses, true) ? EnrichmentStatus::Done : EnrichmentStatus::NotFound;
    }
}
