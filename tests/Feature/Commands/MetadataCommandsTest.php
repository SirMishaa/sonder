<?php

declare(strict_types=1);

use App\Enums\CreditType;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Enums\ResolutionStatus;
use App\Jobs\EnrichContributor;
use App\Jobs\EnrichRecording;
use App\Jobs\ProjectRecordingMetadata;
use App\Jobs\ResolveLibraryTracks;
use App\Jobs\TakeChartSnapshot;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingContributor;
use App\Models\RecordingResolution;
use App\Services\Metadata\LastFm\LastFmGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeLastFmGateway;

it('queues the whole library for resolution', function (): void {
    Queue::fake();
    libraryTrack(null, 'video000001');

    expect(Artisan::call('metadata:enrich'))->toBe(0);

    Queue::assertPushed(ResolveLibraryTracks::class);
});

it('retries what is due, only that, and not again the next day', function (): void {
    Queue::fake();
    $due = Recording::factory()->create();
    $fresh = Recording::factory()->create();
    $failed = Enrichment::factory()->create(['subject_key' => $due->id, 'source' => MetadataSource::CreditsFm, 'endpoint' => 'isrc', 'status' => EnrichmentStatus::Failed, 'next_attempt_at' => now()->subMinute()]);
    Enrichment::factory()->create(['subject_key' => $fresh->id, 'next_attempt_at' => now()->addDay()]);
    Enrichment::factory()->create(['subject_key' => $due->id, 'next_attempt_at' => now()->addDay()]);
    Enrichment::factory()->create(['subject_key' => $fresh->id, 'source' => MetadataSource::CreditsFm, 'endpoint' => 'isrc', 'next_attempt_at' => now()->addDay()]);
    libraryTrack(null, 'video000009');
    RecordingResolution::factory()->create(['external_id' => 'video000009', 'status' => ResolutionStatus::NotFound, 'recording_id' => null, 'next_attempt_at' => now()->subMinute()]);

    expect(Artisan::call('metadata:retry-due'))->toBe(0);

    Queue::assertPushed(EnrichRecording::class, 1);
    Queue::assertPushed(EnrichRecording::class, fn (EnrichRecording $job): bool => $job->recordingId === $due->id && $job->source === MetadataSource::CreditsFm);
    Queue::assertPushed(ResolveLibraryTracks::class, fn (ResolveLibraryTracks $job): bool => $job->tracks[0]['externalId'] === 'video000009');
    expect($failed->fresh()?->next_attempt_at?->toIso8601String())->toBe(now()->addDay()->toIso8601String())
        ->and(RecordingResolution::query()->sole()->next_attempt_at?->toIso8601String())->toBe(now()->addDay()->toIso8601String());
});

it('reprojects every recording without calling any service', function (): void {
    Queue::fake();
    Recording::factory()->count(2)->create();

    expect(Artisan::call('metadata:reproject'))->toBe(0);

    Queue::assertPushed(ProjectRecordingMetadata::class, 2);
});

it('queues one snapshot per chart followed', function (): void {
    Queue::fake();
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());

    expect(Artisan::call('metadata:charts'))->toBe(0);

    Queue::assertPushed(TakeChartSnapshot::class, 4);
    Queue::assertPushed(TakeChartSnapshot::class, fn (TakeChartSnapshot $job): bool => $job->chart === 'country:US');
});

it('takes no chart without a key', function (): void {
    Queue::fake();

    expect(Artisan::call('metadata:charts'))->toBe(0);

    Queue::assertNotPushed(TakeChartSnapshot::class);
});

it('describes what a source never described', function (): void {
    Queue::fake();
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());
    $nameOnly = Recording::factory()->create(['mbid' => null, 'isrc' => null]);
    $artist = Contributor::factory()->create(['mbid' => null]);
    RecordingContributor::query()->create(['recording_id' => $nameOnly->id, 'contributor_id' => $artist->id, 'credit_type' => CreditType::Artist, 'role' => '', 'source' => MetadataSource::LastFm, 'credit_attributes' => []]);

    Artisan::call('metadata:retry-due');

    Queue::assertPushed(EnrichRecording::class, 1);
    Queue::assertPushed(EnrichRecording::class, fn (EnrichRecording $job): bool => $job->recordingId === $nameOnly->id && $job->source === MetadataSource::LastFm);
    Queue::assertPushed(EnrichContributor::class, fn (EnrichContributor $job): bool => $job->contributorId === $artist->id && $job->source === MetadataSource::LastFm);
});

it('queues one Last.fm description per recording when several endpoints are due', function (): void {
    Queue::fake();
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());
    $recording = Recording::factory()->create();
    foreach (['info', 'top_tags', 'similar'] as $endpoint) {
        Enrichment::factory()->create(['subject_key' => $recording->id, 'source' => MetadataSource::LastFm, 'endpoint' => $endpoint, 'next_attempt_at' => now()->subMinute()]);
    }
    foreach ([MetadataSource::CreditsFm, MetadataSource::MusicBrainz] as $source) {
        Enrichment::factory()->create(['subject_key' => $recording->id, 'source' => $source, 'endpoint' => 'x', 'next_attempt_at' => now()->addDay()]);
    }

    Artisan::call('metadata:retry-due');

    Queue::assertPushed(EnrichRecording::class, 1);
});

it('queues the unresolved tracks on demand', function (): void {
    Queue::fake();
    libraryTrack(null, 'video000001');
    libraryTrack(null, 'video000002');
    libraryTrack(null, 'video000003');
    RecordingResolution::factory()->create(['external_id' => 'video000001', 'status' => ResolutionStatus::NotFound, 'recording_id' => null]);
    RecordingResolution::factory()->create(['external_id' => 'video000002', 'status' => ResolutionStatus::Resolved]);

    expect(Artisan::call('metadata:enrich', ['--unresolved' => true]))->toBe(0);

    Queue::assertPushed(ResolveLibraryTracks::class, fn (ResolveLibraryTracks $job): bool => array_column($job->tracks, 'externalId') === ['video000001']);
});

it('forgets the answers about a recording that no longer exists and skips a source switched off', function (): void {
    Queue::fake();
    $gone = fake()->uuid();
    Enrichment::factory()->create(['subject_key' => $gone, 'source' => MetadataSource::LastFm, 'endpoint' => 'info', 'next_attempt_at' => now()->subMinute()]);

    Artisan::call('metadata:retry-due');
    (new EnrichRecording($gone, MetadataSource::MusicBrainz))->handle();

    Queue::assertNotPushed(EnrichRecording::class);
    expect(Enrichment::query()->where('subject_key', $gone)->exists())->toBeFalse();
});
