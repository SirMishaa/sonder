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
