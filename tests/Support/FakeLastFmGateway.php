<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\LastFm\LastFmGateway;
use Throwable;

final class FakeLastFmGateway implements LastFmGateway
{
    /** @var array<string, array<string, mixed>> "artist|title" => payload */
    public array $trackInfos = [];

    /** @var array<string, array<string, mixed>> "artist|title" => payload */
    public array $trackTopTags = [];

    /** @var array<string, array<string, mixed>> "artist|title" => payload */
    public array $trackSimilars = [];

    /** @var array<string, array<string, mixed>> name => payload */
    public array $artistInfos = [];

    /** @var array<string, array<string, mixed>> name => payload */
    public array $artistTopTags = [];

    /** @var array<string, array<string, mixed>> name => payload */
    public array $artistSimilars = [];

    /** @var array<string, array<string, mixed>> country ('' worldwide) => payload */
    public array $topTracks = [];

    public ?Throwable $failure = null;

    /** Refuses every call after this many, like Sonder's own budget. */
    public ?int $refuseAfterCalls = null;

    /** @var list<string> */
    public array $calls = [];

    public function __construct(public bool $enabled = true) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function trackInfo(TrackQuery $query): ?array
    {
        return $this->answer("track_info:{$query->artist}|{$query->title}", $this->trackInfos["{$query->artist}|{$query->title}"] ?? null);
    }

    public function trackTopTags(TrackQuery $query): ?array
    {
        return $this->answer("track_top_tags:{$query->artist}|{$query->title}", $this->trackTopTags["{$query->artist}|{$query->title}"] ?? null);
    }

    public function trackSimilar(TrackQuery $query): ?array
    {
        return $this->answer("track_similar:{$query->artist}|{$query->title}", $this->trackSimilars["{$query->artist}|{$query->title}"] ?? null);
    }

    public function artistInfo(string $name): ?array
    {
        return $this->answer("artist_info:{$name}", $this->artistInfos[$name] ?? null);
    }

    public function artistTopTags(string $name): ?array
    {
        return $this->answer("artist_top_tags:{$name}", $this->artistTopTags[$name] ?? null);
    }

    public function artistSimilar(string $name): ?array
    {
        return $this->answer("artist_similar:{$name}", $this->artistSimilars[$name] ?? null);
    }

    public function topTracks(?string $country): array
    {
        return $this->answer('top_tracks:'.($country ?? ''), $this->topTracks[$country ?? ''] ?? ['tracks' => ['track' => []]]) ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function answer(string $call, ?array $payload): ?array
    {
        if ($this->refuseAfterCalls !== null && count($this->calls) >= $this->refuseAfterCalls) {
            throw new MetadataSourceRateLimited(MetadataSource::LastFm, 1);
        }

        $this->calls[] = $call;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $payload;
    }
}
