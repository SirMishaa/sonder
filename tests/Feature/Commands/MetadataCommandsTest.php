<?php

declare(strict_types=1);

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Enums\ResolutionStatus;
use App\Jobs\EnrichRecording;
use App\Jobs\ProjectRecordingMetadata;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingResolution;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

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
