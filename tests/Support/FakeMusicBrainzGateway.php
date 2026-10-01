<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Throwable;

final class FakeMusicBrainzGateway implements MusicBrainzGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $isrcs = [];

    /** @var array<string, array<string, mixed>> */
    public array $recordings = [];

    /** @var array<string, array<string, mixed>> "artist|title" => payload */
    public array $searches = [];

    /** @var array<string, array<string, mixed>> */
    public array $artists = [];

    public ?Throwable $failure = null;

    /** Refuses every call after this many, like Sonder's own one-call-a-second budget. */
    public ?int $refuseAfterCalls = null;

    /** @var list<string> */
    public array $calls = [];

    public function isrc(string $isrc): ?array
    {
        return $this->answer("isrc:{$isrc}", $this->isrcs[$isrc] ?? null);
    }

    public function recording(string $mbid): ?array
    {
        return $this->answer("recording:{$mbid}", $this->recordings[$mbid] ?? null);
    }

    public function searchRecordings(TrackQuery $query): array
    {
        return $this->answer("search:{$query->artist}|{$query->title}", $this->searches["{$query->artist}|{$query->title}"] ?? ['recordings' => []]) ?? [];
    }

    public function artist(string $mbid): ?array
    {
        return $this->answer("artist:{$mbid}", $this->artists[$mbid] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function answer(string $call, ?array $payload): ?array
    {
        if ($this->refuseAfterCalls !== null && count($this->calls) >= $this->refuseAfterCalls) {
            throw new MetadataSourceRateLimited(MetadataSource::MusicBrainz, 1);
        }

        $this->calls[] = $call;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $payload;
    }
}
