<?php

declare(strict_types=1);

namespace App\Services\Metadata\LastFm;

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\CallBudget;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Spends the `lastfm` budget (four calls a second) before every call,
 * sleeping through the wait for the next second.
 */
final readonly class RateLimitedLastFmGateway implements LastFmGateway
{
    public function __construct(
        private LastFmGateway $gateway,
        private CallBudget $budget,
    ) {}

    public function enabled(): bool
    {
        return $this->gateway->enabled();
    }

    public function trackInfo(TrackQuery $query): ?array
    {
        $this->spend();

        return $this->gateway->trackInfo($query);
    }

    public function trackTopTags(TrackQuery $query): ?array
    {
        $this->spend();

        return $this->gateway->trackTopTags($query);
    }

    public function trackSimilar(TrackQuery $query): ?array
    {
        $this->spend();

        return $this->gateway->trackSimilar($query);
    }

    public function artistInfo(string $name): ?array
    {
        $this->spend();

        return $this->gateway->artistInfo($name);
    }

    public function artistTopTags(string $name): ?array
    {
        $this->spend();

        return $this->gateway->artistTopTags($name);
    }

    public function artistSimilar(string $name): ?array
    {
        $this->spend();

        return $this->gateway->artistSimilar($name);
    }

    public function topTracks(?string $country): array
    {
        $this->spend();

        return $this->gateway->topTracks($country);
    }

    private function spend(): void
    {
        $wait = $this->budget->await(CallBudget::LASTFM);

        if ($wait !== null) {
            throw new MetadataSourceRateLimited(MetadataSource::LastFm, $wait);
        }
    }
}
