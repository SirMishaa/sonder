<?php

declare(strict_types=1);

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\MusicBrainz\HttpMusicBrainzGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEnrichmentTelemetry;

beforeEach(function (): void {
    app()->instance(EnrichmentTelemetry::class, new FakeEnrichmentTelemetry());
    config(['services.musicbrainz.user_agent' => 'Sonder/1.0 ( test@example.test )']);
});

it('looks an isrc up with its artists and identifies itself', function (): void {
    Http::fake(['musicbrainz.org/ws/2/isrc/GBAHT1200434*' => Http::response(metadataFixture('musicbrainz-isrc'))]);

    $payload = resolve(HttpMusicBrainzGateway::class)->isrc('GBAHT1200434');

    expect(data_get($payload, 'recordings.0.id'))->toBe('464d783d-1be7-4e1c-a75b-2b568eb20454');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'Sonder/1.0 ( test@example.test )')
        && data_get($request->data(), 'inc') === 'artist-credits'
        && data_get($request->data(), 'fmt') === 'json');
});

it('returns null for an isrc MusicBrainz does not know', function (): void {
    Http::fake(['musicbrainz.org/ws/2/isrc/*' => Http::response(metadataFixture('musicbrainz-isrc-mismatch'), 404)]);

    expect(resolve(HttpMusicBrainzGateway::class)->isrc('USJKL0700030'))->toBeNull();
});

it('asks for genres and tags on a recording and searches by title and artist', function (): void {
    Http::fake([
        'musicbrainz.org/ws/2/recording/464d783d*' => Http::response(metadataFixture('musicbrainz-recording')),
        'musicbrainz.org/ws/2/recording?*' => Http::response(metadataFixture('musicbrainz-recording-search')),
    ]);
    $gateway = resolve(HttpMusicBrainzGateway::class);

    $gateway->recording('464d783d-1be7-4e1c-a75b-2b568eb20454');
    $gateway->searchRecordings(new TrackQuery('Survival', 'Muse'));

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'inc') === 'genres+tags+artist-credits+isrcs');
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'query') === 'recording:"Survival" AND artist:"Muse"');
});

it('treats a busy server as a rate limit', function (): void {
    Http::fake(['musicbrainz.org/*' => Http::response(['error' => 'busy'], 503)]);

    expect(fn (): ?array => resolve(HttpMusicBrainzGateway::class)->artist('9c9f1380-2516-4fc9-a3e6-f9f61941d090'))
        ->toThrow(MetadataSourceRateLimited::class);
});
