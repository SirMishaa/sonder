<?php

declare(strict_types=1);

use App\Actions\StartYouTubeMusicSync;
use App\Enums\YouTubeMusicSyncStatus;
use App\Jobs\SyncYouTubeMusicLibrary;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Facades\Queue;

it('creates a pending sync and dispatches the job', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();

    $sync = resolve(StartYouTubeMusicSync::class)->handle($account);

    expect($sync->youtube_music_account_id)->toBe($account->id)
        ->and($sync->status)->toBe(YouTubeMusicSyncStatus::Pending);

    Queue::assertPushed(
        SyncYouTubeMusicLibrary::class,
        fn (SyncYouTubeMusicLibrary $job): bool => $job->syncId === $sync->id
            && $job->youTubeMusicAccountId === $account->id,
    );
});

it('reuses an active sync instead of starting a second one', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();
    $existing = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
    ]);

    $sync = resolve(StartYouTubeMusicSync::class)->handle($account);

    expect($sync->id)->toBe($existing->id)
        ->and(YouTubeMusicSync::query()->count())->toBe(1);

    Queue::assertNotPushed(SyncYouTubeMusicLibrary::class);
});

it('starts a new sync once the previous one finished', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();
    YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Completed,
    ]);

    resolve(StartYouTubeMusicSync::class)->handle($account);

    expect(YouTubeMusicSync::query()->count())->toBe(2);
    Queue::assertPushed(SyncYouTubeMusicLibrary::class);
});

it('replaces a sync that stopped making progress, closing it as interrupted', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();
    $orphan = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
        'updated_at' => now()->subMinutes(6),
    ]);

    $sync = resolve(StartYouTubeMusicSync::class)->handle($account);

    expect($sync->id)->not->toBe($orphan->id)
        ->and($orphan->refresh()->status)->toBe(YouTubeMusicSyncStatus::Failed)
        ->and($orphan->error_message)->toBe('The sync stopped before it could finish.')
        ->and($orphan->finished_at)->not->toBeNull();
    Queue::assertPushed(SyncYouTubeMusicLibrary::class, fn (SyncYouTubeMusicLibrary $job): bool => $job->syncId === $sync->id);
});

it('keeps reusing a sync that made progress a few minutes ago', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();
    $running = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
        'updated_at' => now()->subMinutes(4),
    ]);

    expect(resolve(StartYouTubeMusicSync::class)->handle($account)->id)->toBe($running->id);
});

it('waits for a sync paused by the call budget until its job would give up', function (int $minutesAgo, bool $isReplaced): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();
    $paused = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Pending,
        'updated_at' => now()->subMinutes($minutesAgo),
    ]);

    $sync = resolve(StartYouTubeMusicSync::class)->handle($account);

    expect($sync->id !== $paused->id)->toBe($isReplaced);
})->with([
    'paused 90 minutes ago' => [90, false],
    'paused 3 hours ago' => [180, true],
]);
