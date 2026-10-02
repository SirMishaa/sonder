<?php

declare(strict_types=1);

namespace App\Services\Metadata\LastFm;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Last.fm answers errors with HTTP 200 and an `error` code: 6 is "not
 * found", 29 the rate limit. The key travels in the query string, so no
 * message built here ever contains the URL.
 */
final readonly class HttpLastFmGateway implements LastFmGateway
{
    private const int NOT_FOUND = 6;

    private const int RATE_LIMITED = 29;

    private const int SIMILAR_LIMIT = 50;

    private const int CHART_LIMIT = 200;

    public function __construct(private EnrichmentTelemetry $telemetry) {}

    public function enabled(): bool
    {
        return config()->string('services.lastfm.key') !== '';
    }

    public function trackInfo(TrackQuery $query): ?array
    {
        return $this->call('track_info', 'track.getInfo', ['artist' => $query->artist, 'track' => $query->title]);
    }

    public function trackTopTags(TrackQuery $query): ?array
    {
        return $this->call('track_top_tags', 'track.getTopTags', ['artist' => $query->artist, 'track' => $query->title]);
    }

    public function trackSimilar(TrackQuery $query): ?array
    {
        return $this->call('track_similar', 'track.getSimilar', ['artist' => $query->artist, 'track' => $query->title, 'limit' => self::SIMILAR_LIMIT]);
    }

    public function artistInfo(string $name): ?array
    {
        return $this->call('artist_info', 'artist.getInfo', ['artist' => $name]);
    }

    public function artistTopTags(string $name): ?array
    {
        return $this->call('artist_top_tags', 'artist.getTopTags', ['artist' => $name]);
    }

    public function artistSimilar(string $name): ?array
    {
        return $this->call('artist_similar', 'artist.getSimilar', ['artist' => $name, 'limit' => self::SIMILAR_LIMIT]);
    }

    public function topTracks(?string $country): array
    {
        $payload = $country === null
            ? $this->call('chart', 'chart.getTopTracks', ['limit' => self::CHART_LIMIT])
            : $this->call('geo', 'geo.getTopTracks', ['country' => $country, 'limit' => self::CHART_LIMIT]);

        return $payload ?? ['tracks' => ['track' => []]];
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null
     */
    private function call(string $endpoint, string $method, array $query): ?array
    {
        $started = microtime(true);

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->get(config()->string('services.lastfm.url'), [
                    ...$query,
                    'method' => $method,
                    'api_key' => config()->string('services.lastfm.key'),
                    'format' => 'json',
                    'autocorrect' => 1,
                ]);
        } catch (ConnectionException) {
            $this->telemetry->lookup(MetadataSource::LastFm, $endpoint, LookupOutcome::Failed, microtime(true) - $started);

            throw new MetadataSourceUnavailable(MetadataSource::LastFm, "connection failed on {$endpoint}");
        }

        $seconds = microtime(true) - $started;
        $payload = $response->json();
        $error = is_array($payload) && is_int($payload['error'] ?? null) ? $payload['error'] : null;

        if ($response->status() === 429 || $error === self::RATE_LIMITED) {
            throw new MetadataSourceRateLimited(MetadataSource::LastFm, max(1, (int) $response->header('Retry-After') ?: 60));
        }

        if ($error === self::NOT_FOUND) {
            $this->telemetry->lookup(MetadataSource::LastFm, $endpoint, LookupOutcome::NotFound, $seconds);

            return null;
        }

        if (! $response->successful() || ! is_array($payload) || $error !== null) {
            $this->telemetry->lookup(MetadataSource::LastFm, $endpoint, LookupOutcome::Failed, $seconds);

            throw new MetadataSourceUnavailable(MetadataSource::LastFm, $error === null ? "HTTP {$response->status()} on {$endpoint}" : "error {$error} on {$endpoint}");
        }

        $this->telemetry->lookup(MetadataSource::LastFm, $endpoint, LookupOutcome::Found, $seconds);

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
