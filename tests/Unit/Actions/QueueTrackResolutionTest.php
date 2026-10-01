<?php

declare(strict_types=1);

use App\Actions\QueueTrackResolution;
use App\Enums\ResolutionStatus;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Playlist;
use App\Models\RecordingResolution;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\Queue;

it('queues each unresolved video once, in batches of 25', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();
    $playlist = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $other = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();

    foreach (range(0, 29) as $index) {
        libraryTrack($playlist, sprintf('video%06d', $index));
    }
    libraryTrack($other, 'video000000');
    libraryTrack($other, null);
    RecordingResolution::factory()->create(['external_id' => 'video000001']);

    $queued = resolve(QueueTrackResolution::class)->handle($account);

    expect($queued)->toBe(29);
    Queue::assertPushed(ResolveLibraryTracks::class, 2);
    Queue::assertPushed(ResolveLibraryTracks::class, fn (ResolveLibraryTracks $job): bool => count($job->tracks) === 25);
});

it('marks queued videos pending so they are not queued twice', function (): void {
    Queue::fake();
    libraryTrack(null, 'video000001');

    resolve(QueueTrackResolution::class)->handle();
    $again = resolve(QueueTrackResolution::class)->handle();

    expect($again)->toBe(0)
        ->and(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::Pending)
        ->and(RecordingResolution::query()->sole()->next_attempt_at?->toIso8601String())->toBe(now()->addDay()->toIso8601String());
    Queue::assertPushed(ResolveLibraryTracks::class, 1);
});

it('queues resolved videos again when refreshing', function (): void {
    Queue::fake();
    libraryTrack(null, 'video000001');
    RecordingResolution::factory()->create(['external_id' => 'video000001']);

    expect(resolve(QueueTrackResolution::class)->handle(refresh: true))->toBe(1)
        ->and(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::Resolved);
});
