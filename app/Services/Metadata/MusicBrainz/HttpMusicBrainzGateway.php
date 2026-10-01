<?php

declare(strict_types=1);

namespace App\Services\Metadata\MusicBrainz;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final readonly class HttpMusicBrainzGateway implements MusicBrainzGateway
{
    public function __construct(private EnrichmentTelemetry $telemetry) {}

    public function isrc(string $isrc): ?array
    {
        return $this->get('isrc', '/isrc/'.rawurlencode($isrc), ['inc' => 'artist-credits']);
    }

    public function recording(string $mbid): ?array
    {
        return $this->get('recording', '/recording/'.rawurlencode($mbid), ['inc' => 'genres+tags+artist-credits+isrcs']);
    }

    public function searchRecordings(TrackQuery $query): array
    {
        $escape = fn (string $text): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $text);

        return $this->get('search', '/recording', [
            'query' => 'recording:"'.$escape($query->title).'" AND artist:"'.$escape($query->artist).'"',
            'limit' => 5,
        ]) ?? ['recordings' => []];
    }

    public function artist(string $mbid): ?array
    {
        return $this->get('artist', '/artist/'.rawurlencode($mbid), ['inc' => 'genres+tags']);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null
     */
    private function get(string $endpoint, string $path, array $query): ?array
    {
        $started = microtime(true);

        try {
            $response = Http::baseUrl(config()->string('services.musicbrainz.url'))
                ->withUserAgent(config()->string('services.musicbrainz.user_agent'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->get($path, [...$query, 'fmt' => 'json']);
        } catch (ConnectionException $exception) {
            $this->telemetry->lookup(MetadataSource::MusicBrainz, $endpoint, LookupOutcome::Failed, microtime(true) - $started);

            throw new MetadataSourceUnavailable(MetadataSource::MusicBrainz, $exception->getMessage());
        }

        $seconds = microtime(true) - $started;

        // MusicBrainz answers 503 when a client goes over its rate limit.
        if (in_array($response->status(), [429, 503], true)) {
            throw new MetadataSourceRateLimited(MetadataSource::MusicBrainz, max(1, (int) $response->header('Retry-After') ?: 5));
        }

        if ($response->status() === 404) {
            $this->telemetry->lookup(MetadataSource::MusicBrainz, $endpoint, LookupOutcome::NotFound, $seconds);

            return null;
        }

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload)) {
            $this->telemetry->lookup(MetadataSource::MusicBrainz, $endpoint, LookupOutcome::Failed, $seconds);

            throw new MetadataSourceUnavailable(MetadataSource::MusicBrainz, "HTTP {$response->status()} on {$endpoint}");
        }

        $this->telemetry->lookup(MetadataSource::MusicBrainz, $endpoint, LookupOutcome::Found, $seconds);

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
