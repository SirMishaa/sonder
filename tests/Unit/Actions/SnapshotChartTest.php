<?php

declare(strict_types=1);

use App\Actions\SnapshotChart;
use App\Models\ChartEntry;
use App\Models\ChartSnapshot;
use App\Models\Recording;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\LastFm\LastFmGateway;
use Tests\Support\FakeEnrichmentTelemetry;
use Tests\Support\FakeLastFmGateway;

beforeEach(function (): void {
    $this->lastFm = new FakeLastFmGateway();
    $this->telemetry = new FakeEnrichmentTelemetry();
    app()->instance(LastFmGateway::class, $this->lastFm);
    app()->instance(EnrichmentTelemetry::class, $this->telemetry);
    $this->lastFm->topTracks[''] = metadataFixture('lastfm-chart-top-tracks');
    $this->lastFm->topTracks['belgium'] = metadataFixture('lastfm-geo-top-tracks');
});

it('takes a chart and links the tracks the library knows', function (): void {
    $known = Recording::factory()->create(['title' => 'Nicole Kidman', 'artist_name' => 'Adéla']);

    $snapshot = resolve(SnapshotChart::class)->handle('global');

    expect($snapshot?->entries()->count())->toBe(5)
        ->and(ChartEntry::query()->where('rank', 1)->sole()->recording_id)->toBe($known->id)
        ->and(ChartEntry::query()->where('rank', 2)->sole()->recording_id)->toBeNull()
        ->and($this->telemetry->chartEntries['global'])->toBe(['linked' => 1, 'unlinked' => 4]);
});

it('asks a country chart by its English name', function (): void {
    resolve(SnapshotChart::class)->handle('country:BE');

    expect($this->lastFm->calls)->toBe(['top_tracks:belgium'])
        ->and(ChartEntry::query()->where('rank', 1)->sole()->title)->toBe("Ain't In LA");
});

it('takes each chart once a day', function (): void {
    resolve(SnapshotChart::class)->handle('global');

    expect(resolve(SnapshotChart::class)->handle('global'))->toBeNull()
        ->and($this->lastFm->calls)->toBe(['top_tracks:']);

    $this->travel(1)->days();
    resolve(SnapshotChart::class)->handle('global');

    expect(ChartSnapshot::query()->count())->toBe(2);
});

it('refuses a chart it does not follow', function (): void {
    expect(fn (): ?ChartSnapshot => resolve(SnapshotChart::class)->handle('country:XX'))->toThrow(InvalidArgumentException::class);
});
