<?php

declare(strict_types=1);

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\LastFm\HttpLastFmGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpClientInstrumentation;
use Tests\Support\FakeEnrichmentTelemetry;

beforeEach(function (): void {
    app()->instance(EnrichmentTelemetry::class, new FakeEnrichmentTelemetry());
    config(['services.lastfm.key' => 'test-key']);
});

it('asks for a track by artist and title, corrected, with the key', function (): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response(metadataFixture('lastfm-track-info'))]);

    $payload = resolve(HttpLastFmGateway::class)->trackInfo(new TrackQuery('Mr. Loverman', 'Ricky Montgomery'));

    expect(data_get($payload, 'track.listeners'))->toBe('833437');
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'method') === 'track.getInfo'
        && data_get($request->data(), 'artist') === 'Ricky Montgomery'
        && data_get($request->data(), 'track') === 'Mr. Loverman'
        && data_get($request->data(), 'autocorrect') === 1
        && data_get($request->data(), 'format') === 'json'
        && data_get($request->data(), 'api_key') === 'test-key');
});

it('returns null when Last.fm does not know the subject', function (): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response(metadataFixture('lastfm-not-found'))]);

    expect(resolve(HttpLastFmGateway::class)->artistInfo('zzqx nonexistent artist'))->toBeNull();
});

it('treats error 29 and HTTP 429 as a rate limit', function (int $status, array $body): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response($body, $status)]);

    expect(fn (): ?array => resolve(HttpLastFmGateway::class)->trackTopTags(new TrackQuery('Loreley', 'Lord of the Lost')))
        ->toThrow(MetadataSourceRateLimited::class);
})->with([
    [200, ['error' => 29, 'message' => 'Rate Limit Exceeded']],
    [429, []],
]);

it('treats other errors as unavailable', function (int $status, array $body): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response($body, $status)]);

    expect(fn (): ?array => resolve(HttpLastFmGateway::class)->artistSimilar('Lord of the Lost'))
        ->toThrow(MetadataSourceUnavailable::class);
})->with([
    [200, ['error' => 11, 'message' => 'Service Offline']],
    [200, ['error' => 10, 'message' => 'Invalid API key']],
    [500, []],
]);

it('keeps the key out of a connection error', function (): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::failedConnection('cURL error 28 for https://ws.audioscrobbler.com/2.0/?api_key=test-key')]);

    try {
        resolve(HttpLastFmGateway::class)->trackInfo(new TrackQuery('Loreley', 'Lord of the Lost'));
        $this->fail('Expected the call to fail.');
    } catch (MetadataSourceUnavailable $exception) {
        expect($exception->getMessage())->not->toContain('test-key');
    }
});

it('asks for the charts worldwide and per country, top 200', function (): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response(metadataFixture('lastfm-chart-top-tracks'))]);
    $gateway = resolve(HttpLastFmGateway::class);

    $gateway->topTracks(null);
    $gateway->topTracks('belgium');

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'method') === 'chart.getTopTracks' && data_get($request->data(), 'limit') === 200);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'method') === 'geo.getTopTracks' && data_get($request->data(), 'country') === 'belgium');
});

it('is enabled only with a key', function (): void {
    expect(resolve(HttpLastFmGateway::class)->enabled())->toBeTrue();

    config(['services.lastfm.key' => '']);

    expect(resolve(HttpLastFmGateway::class)->enabled())->toBeFalse();
});

it('keeps the key out of traces', function (): void {
    expect(config('opentelemetry.instrumentation.'.HttpClientInstrumentation::class.'.sensitive_query_parameters'))->toContain('api_key');
});
