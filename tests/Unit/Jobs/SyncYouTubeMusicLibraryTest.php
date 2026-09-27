<?php

declare(strict_types=1);

use App\Actions\SyncPlaylistsFromYouTubeMusicAction;
use App\Enums\YouTubeMusicSyncStatus;
use App\Events\YouTubeMusicSyncUpdated;
use App\Jobs\SyncYouTubeMusicLibrary;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeYouTubeMusicClient;

it('syncs the library and marks the sync completed', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);

    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus'),
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL2', title: 'Gaming'),
    ];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus');
    $this->fakeYouTubeMusic()->tracks['PL2'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL2', title: 'Gaming');

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    $sync->refresh();

    expect($sync->status)->toBe(YouTubeMusicSyncStatus::Completed)
        ->and($sync->total_playlists)->toBe(2)
        ->and($sync->synced_playlists)->toBe(2)
        ->and($sync->started_at)->not->toBeNull()
        ->and($sync->finished_at)->not->toBeNull()
        ->and(Playlist::query()->count())->toBe(2);

    // Syncing, 0/2, PL1 done (1/2), PL2 done (2/2), Completed.
    Event::assertDispatchedTimes(YouTubeMusicSyncUpdated::class, 5);
});

it('marks the sync failed when YouTube Music cannot be reached', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);

    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeYouTubeMusic()->shouldFail = true;

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    $sync->refresh();

    expect($sync->status)->toBe(YouTubeMusicSyncStatus::Failed)
        ->and($sync->error_message)->not->toBeNull()
        ->and($sync->finished_at)->not->toBeNull();

    // Syncing, Failed.
    Event::assertDispatchedTimes(YouTubeMusicSyncUpdated::class, 2);
});

it('flags the cookie when a sync cannot reach YouTube Music', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);
    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeYouTubeMusic()->shouldFail = true;

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    expect($account->refresh()->hasExpiredCookie())->toBeTrue();
});

it('clears the cookie flag once a sync succeeds', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);
    $account = YouTubeMusicAccount::factory()->expired()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    expect($account->refresh()->hasExpiredCookie())->toBeFalse();
});
