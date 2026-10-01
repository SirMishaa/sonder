<?php

declare(strict_types=1);

use App\Actions\DescribeRecording;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Tests\Support\FakeCreditsFmGateway;
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
