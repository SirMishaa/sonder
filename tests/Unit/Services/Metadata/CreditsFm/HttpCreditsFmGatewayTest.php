<?php

declare(strict_types=1);

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\CreditsFm\HttpCreditsFmGateway;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEnrichmentTelemetry;

beforeEach(function (): void {
    $this->telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $this->telemetry);
});

it('resolves a batch to one isrc per query, in order', function (): void {
    Http::fake(['api.credits.fm/v1/resolve/batch' => Http::response(metadataFixture('credits-fm-resolve-batch'))]);

    $isrcs = resolve(HttpCreditsFmGateway::class)->resolveBatch([
        new TrackQuery('Survival', 'Muse'),
        new TrackQuery('Enter Sandman', 'Metallica'),
        new TrackQuery('zzzz nothing here qqq', 'Nobody Atall'),
    ]);

    expect($isrcs)->toBe(['GBAHT1200434', 'GBF089190013', 'USJKL0700030']);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'contribute') === false
        && data_get($request->data(), 'tracks.0') === ['name' => 'Survival', 'artist' => 'Muse']);
    expect($this->telemetry->lookups)->toBe([['source' => 'credits_fm', 'endpoint' => 'resolve_batch', 'outcome' => 'found']]);
});

it('returns the isrc detail, or null when credits.fm does not know it', function (): void {
    Http::fake([
        'api.credits.fm/v1/isrc/GBAHT1200434*' => Http::response(metadataFixture('credits-fm-isrc')),
        'api.credits.fm/v1/isrc/XX0000000000*' => Http::response(['error' => 'not found'], 404),
    ]);
    $gateway = resolve(HttpCreditsFmGateway::class);

    expect($gateway->isrc('GBAHT1200434')['recording_title'] ?? null)->toBe('Survival')
        ->and($gateway->isrc('XX0000000000'))->toBeNull();
});

it('turns a 429 into a rate limit and a 503 into an outage', function (): void {
    Http::fake([
        'api.credits.fm/v1/isrc/AAAAA0000001*' => Http::response('', 429, ['Retry-After' => '30']),
        'api.credits.fm/v1/isrc/AAAAA0000002*' => Http::response('', 503),
    ]);
    $gateway = resolve(HttpCreditsFmGateway::class);

    expect(fn (): ?array => $gateway->isrc('AAAAA0000001'))->toThrow(new MetadataSourceRateLimited(MetadataSource::CreditsFm, 30))
        ->and(fn (): ?array => $gateway->isrc('AAAAA0000002'))->toThrow(MetadataSourceUnavailable::class);
});
