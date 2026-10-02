<?php

declare(strict_types=1);

use App\Actions\DescribeRecording;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\LastFm\LastFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Tests\Support\FakeCreditsFmGateway;
use Tests\Support\FakeLastFmGateway;
use Tests\Support\FakeMusicBrainzGateway;

beforeEach(function (): void {
    $this->creditsFm = new FakeCreditsFmGateway();
    $this->musicBrainz = new FakeMusicBrainzGateway();
    app()->instance(CreditsFmGateway::class, $this->creditsFm);
    app()->instance(MusicBrainzGateway::class, $this->musicBrainz);
});

it('stores the credits.fm detail of the recording isrc', function (): void {
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    $this->creditsFm->details['GBAHT1200434'] = metadataFixture('credits-fm-isrc');

    $status = resolve(DescribeRecording::class)->handle($recording, MetadataSource::CreditsFm);

    expect($status)->toBe(EnrichmentStatus::Done)
        ->and(data_get(Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::CreditsFm, 'isrc'), 'iswc'))->toBe('T-912674410-3');
});

it('stores the MusicBrainz recording, or not found', function (): void {
    $known = Recording::factory()->create(['mbid' => '464d783d-1be7-4e1c-a75b-2b568eb20454']);
    $unknown = Recording::factory()->create();
    $this->musicBrainz->recordings['464d783d-1be7-4e1c-a75b-2b568eb20454'] = metadataFixture('musicbrainz-recording');

    expect(resolve(DescribeRecording::class)->handle($known, MetadataSource::MusicBrainz))->toBe(EnrichmentStatus::Done)
        ->and(resolve(DescribeRecording::class)->handle($unknown, MetadataSource::MusicBrainz))->toBe(EnrichmentStatus::NotFound);
});

it('skips a source that cannot identify the recording', function (): void {
    $recording = Recording::factory()->create(['mbid' => null, 'isrc' => 'GBAHT1200434']);

    expect(resolve(DescribeRecording::class)->handle($recording, MetadataSource::MusicBrainz))->toBeNull()
        ->and($this->musicBrainz->calls)->toBe([]);
});

it('asks Last.fm for info, tags and similar tracks by title and artist', function (): void {
    $lastFm = new FakeLastFmGateway();
    app()->instance(LastFmGateway::class, $lastFm);
    $recording = Recording::factory()->create(['mbid' => null, 'isrc' => null, 'title' => 'Loreley', 'artist_name' => 'Lord of the Lost']);
    $lastFm->trackInfos['Lord of the Lost|Loreley'] = metadataFixture('lastfm-track-info');

    $status = resolve(DescribeRecording::class)->handle($recording, MetadataSource::LastFm);

    expect($status)->toBe(EnrichmentStatus::Done)
        ->and($lastFm->calls)->toBe(['track_info:Lord of the Lost|Loreley', 'track_top_tags:Lord of the Lost|Loreley', 'track_similar:Lord of the Lost|Loreley'])
        ->and(Enrichment::query()->where('source', MetadataSource::LastFm)->pluck('status', 'endpoint')->all())
        ->toEqual(['info' => EnrichmentStatus::Done, 'top_tags' => EnrichmentStatus::NotFound, 'similar' => EnrichmentStatus::NotFound]);
});

it('asks Last.fm only for what is due', function (): void {
    $lastFm = new FakeLastFmGateway();
    app()->instance(LastFmGateway::class, $lastFm);
    $recording = Recording::factory()->create(['title' => 'Loreley', 'artist_name' => 'Lord of the Lost']);
    $lastFm->trackInfos['Lord of the Lost|Loreley'] = metadataFixture('lastfm-track-info');
    $describe = resolve(DescribeRecording::class);
    $describe->handle($recording, MetadataSource::LastFm);
    $lastFm->calls = [];

    expect($describe->handle($recording, MetadataSource::LastFm))->toBeNull()
        ->and($lastFm->calls)->toBe([]);

    $this->travel(8)->days();
    $describe->handle($recording, MetadataSource::LastFm);

    expect($lastFm->calls)->toBe(['track_info:Lord of the Lost|Loreley']);
});

it('resumes after a rate limit without asking again', function (): void {
    $lastFm = new FakeLastFmGateway();
    $lastFm->refuseAfterCalls = 1;
    app()->instance(LastFmGateway::class, $lastFm);
    $recording = Recording::factory()->create(['title' => 'Loreley', 'artist_name' => 'Lord of the Lost']);

    expect(fn (): ?EnrichmentStatus => resolve(DescribeRecording::class)->handle($recording, MetadataSource::LastFm))
        ->toThrow(MetadataSourceRateLimited::class);

    $lastFm->refuseAfterCalls = null;
    $lastFm->calls = [];
    resolve(DescribeRecording::class)->handle($recording, MetadataSource::LastFm);

    expect($lastFm->calls)->toBe(['track_top_tags:Lord of the Lost|Loreley', 'track_similar:Lord of the Lost|Loreley']);
});

it('leaves Last.fm out of the sources without a key', function (): void {
    expect(resolve(DescribeRecording::class)->sources())->toBe([MetadataSource::CreditsFm, MetadataSource::MusicBrainz])
        ->and(resolve(DescribeRecording::class)->handle(Recording::factory()->create(), MetadataSource::LastFm))->toBeNull();

    app()->instance(LastFmGateway::class, new FakeLastFmGateway());

    expect(resolve(DescribeRecording::class)->sources())->toBe([MetadataSource::CreditsFm, MetadataSource::MusicBrainz, MetadataSource::LastFm]);
});
