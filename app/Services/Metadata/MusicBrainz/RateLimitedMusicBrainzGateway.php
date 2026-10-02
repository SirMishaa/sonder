<?php

declare(strict_types=1);

namespace App\Services\Metadata\MusicBrainz;

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\CallBudget;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Spends the `musicbrainz` budget (one call a second) before every call,
 * sleeping through the wait for the next second.
 */
final readonly class RateLimitedMusicBrainzGateway implements MusicBrainzGateway
{
    public function __construct(
        private MusicBrainzGateway $gateway,
        private CallBudget $budget,
    ) {}

    public function isrc(string $isrc): ?array
    {
        $this->spend();

        return $this->gateway->isrc($isrc);
    }

    public function recording(string $mbid): ?array
    {
        $this->spend();

        return $this->gateway->recording($mbid);
    }

    public function searchRecordings(TrackQuery $query): array
    {
        $this->spend();

        return $this->gateway->searchRecordings($query);
    }

    public function artist(string $mbid): ?array
    {
        $this->spend();

        return $this->gateway->artist($mbid);
    }

    private function spend(): void
    {
        $wait = $this->budget->await(CallBudget::MUSICBRAINZ);

        if ($wait !== null) {
            throw new MetadataSourceRateLimited(MetadataSource::MusicBrainz, $wait);
        }
    }
}
