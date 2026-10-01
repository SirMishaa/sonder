<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;

final readonly class DescribeRecording
{
    /** @var list<MetadataSource> */
    public const array SOURCES = [MetadataSource::CreditsFm, MetadataSource::MusicBrainz];

    public function __construct(
        private CreditsFmGateway $creditsFm,
        private MusicBrainzGateway $musicBrainz,
    ) {}

    /**
     * The endpoint a source is asked about a recording with.
     */
    public static function endpoint(MetadataSource $source): string
    {
        return $source === MetadataSource::CreditsFm ? 'isrc' : 'recording';
    }

    /**
     * Fetches and stores what one source says about the recording. Null when
     * the source has no identifier to ask with. Failures propagate.
     */
    public function handle(Recording $recording, MetadataSource $source): ?EnrichmentStatus
    {
        if ($source === MetadataSource::CreditsFm && $recording->isrc !== null) {
            $payload = $this->creditsFm->isrc($recording->isrc);
        } elseif ($source === MetadataSource::MusicBrainz && $recording->mbid !== null) {
            $payload = $this->musicBrainz->recording($recording->mbid);
        } else {
            return null;
        }

        $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
        Enrichment::store(Enrichment::RECORDING, $recording->id, $source, self::endpoint($source), $status, $payload);

        return $status;
    }
}
