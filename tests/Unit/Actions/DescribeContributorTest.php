<?php

declare(strict_types=1);

use App\Actions\DescribeContributor;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
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
