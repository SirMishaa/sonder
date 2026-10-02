<?php

declare(strict_types=1);

use App\Actions\DescribeContributor;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Services\Metadata\LastFm\LastFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Tests\Support\FakeLastFmGateway;
use Tests\Support\FakeMusicBrainzGateway;

it('stores the MusicBrainz artist of a contributor with an mbid', function (): void {
    $musicBrainz = new FakeMusicBrainzGateway();
    $musicBrainz->artists['9c9f1380-2516-4fc9-a3e6-f9f61941d090'] = metadataFixture('musicbrainz-artist');
    app()->instance(MusicBrainzGateway::class, $musicBrainz);
    $muse = Contributor::factory()->create(['mbid' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090']);
    $nameOnly = Contributor::factory()->create(['mbid' => null]);

    expect(resolve(DescribeContributor::class)->handle($muse, MetadataSource::MusicBrainz))->toBe(EnrichmentStatus::Done)
        ->and(Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $muse->id, MetadataSource::MusicBrainz, 'artist'))->not->toBeNull()
        ->and(resolve(DescribeContributor::class)->handle($nameOnly, MetadataSource::MusicBrainz))->toBeNull();
});

it('names the sources that never described a contributor', function (): void {
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());
    $withMbid = Contributor::factory()->create();
    $nameOnly = Contributor::factory()->create(['mbid' => null]);
    Enrichment::store(Enrichment::CONTRIBUTOR, $withMbid->id, MetadataSource::MusicBrainz, 'artist', EnrichmentStatus::Done, ['id' => 'x']);

    expect(resolve(DescribeContributor::class)->undescribedSources($withMbid))->toBe([MetadataSource::LastFm])
        ->and(resolve(DescribeContributor::class)->undescribedSources($nameOnly))->toBe([MetadataSource::LastFm]);

    app()->instance(LastFmGateway::class, new FakeLastFmGateway(enabled: false));

    expect(resolve(DescribeContributor::class)->undescribedSources($nameOnly))->toBe([]);
});

it('asks Last.fm about an artist by name, with or without an MBID', function (): void {
    $lastFm = new FakeLastFmGateway();
    app()->instance(LastFmGateway::class, $lastFm);
    $contributor = Contributor::factory()->create(['name' => 'Lord of the Lost', 'mbid' => null]);
    $lastFm->artistInfos['Lord of the Lost'] = metadataFixture('lastfm-artist-info');

    $status = resolve(DescribeContributor::class)->handle($contributor, MetadataSource::LastFm);

    expect($status)->toBe(EnrichmentStatus::Done)
        ->and($lastFm->calls)->toBe(['artist_info:Lord of the Lost', 'artist_top_tags:Lord of the Lost', 'artist_similar:Lord of the Lost']);
});
