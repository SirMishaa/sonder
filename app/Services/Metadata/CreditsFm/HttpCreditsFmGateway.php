<?php

declare(strict_types=1);

namespace App\Services\Metadata\CreditsFm;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class HttpCreditsFmGateway implements CreditsFmGateway
{
    public function __construct(private EnrichmentTelemetry $telemetry) {}

    public function resolveBatch(array $queries): array
    {
        $response = $this->send('resolve_batch', fn (PendingRequest $http): Response => $http->post('/v1/resolve/batch', [
            'tracks' => array_map(fn (TrackQuery $query): array => ['name' => $query->title, 'artist' => $query->artist], $queries),
            'contribute' => false,
        ]));

        $results = $response?->json('results');

        if (! is_array($results)) {
            throw new MetadataSourceUnavailable(MetadataSource::CreditsFm, 'resolve/batch returned no results');
        }

        return array_map(function (int $index) use ($results): ?string {
            $row = $results[$index] ?? null;
            $isrc = is_array($row) ? ($row['isrc'] ?? null) : null;

            return is_string($isrc) && $isrc !== '' ? $isrc : null;
        }, array_keys($queries));
    }

    public function isrc(string $isrc): ?array
    {
        $response = $this->send('isrc', fn (PendingRequest $http): Response => $http->get('/v1/isrc/'.rawurlencode($isrc), ['contribute' => 'false']));
        $payload = $response?->json();

        if ($response !== null && ! is_array($payload)) {
            throw new MetadataSourceUnavailable(MetadataSource::CreditsFm, 'isrc returned no object');
        }

        /** @var array<string, mixed>|null $payload */
        return $payload;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return Response|null null on 404
     */
    private function send(string $endpoint, callable $call): ?Response
    {
        $started = microtime(true);

        try {
            $response = $call(Http::baseUrl(config()->string('services.credits_fm.url'))->acceptJson()->connectTimeout(5)->timeout(30));
        } catch (ConnectionException $exception) {
            $this->telemetry->lookup(MetadataSource::CreditsFm, $endpoint, LookupOutcome::Failed, microtime(true) - $started);

            throw new MetadataSourceUnavailable(MetadataSource::CreditsFm, $exception->getMessage());
        }

        $seconds = microtime(true) - $started;

        if ($response->status() === 429) {
            throw new MetadataSourceRateLimited(MetadataSource::CreditsFm, max(1, (int) $response->header('Retry-After') ?: 60));
        }

        if ($response->status() === 404) {
            $this->telemetry->lookup(MetadataSource::CreditsFm, $endpoint, LookupOutcome::NotFound, $seconds);

            return null;
        }

        if (! $response->successful()) {
            $this->telemetry->lookup(MetadataSource::CreditsFm, $endpoint, LookupOutcome::Failed, $seconds);

            throw new MetadataSourceUnavailable(MetadataSource::CreditsFm, "HTTP {$response->status()} on {$endpoint}");
        }

        $this->telemetry->lookup(MetadataSource::CreditsFm, $endpoint, LookupOutcome::Found, $seconds);

        return $response;
    }
}
