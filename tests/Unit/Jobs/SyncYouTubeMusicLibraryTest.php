<?php

declare(strict_types=1);

use App\Actions\SyncPlaylistsFromYouTubeMusicAction;
use App\Enums\YouTubeMusicSyncStatus;
use App\Events\YouTubeMusicSyncUpdated;
use App\Exceptions\Providers\CredentialsRejected;
use App\Jobs\SyncYouTubeMusicLibrary;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FakeProviderAdapter;

it('syncs the library and marks the sync completed', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);

    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeProvider()->playlists = [
        FakeProviderAdapter::aPlaylistSummary(id: 'PL1', title: 'Deep Focus'),
        FakeProviderAdapter::aPlaylistSummary(id: 'PL2', title: 'Gaming'),
    ];
    $this->fakeProvider()->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1', title: 'Deep Focus');
    $this->fakeProvider()->tracks['PL2'] = FakeProviderAdapter::aPlaylist(id: 'PL2', title: 'Gaming');

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
    $this->fakeProvider()->shouldFail = true;
    Exceptions::fake();

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    $sync->refresh();

    expect($sync->status)->toBe(YouTubeMusicSyncStatus::Failed)
        ->and($sync->error_message)->not->toBeNull()
        ->and($sync->finished_at)->not->toBeNull();

    // Syncing, Failed.
    Event::assertDispatchedTimes(YouTubeMusicSyncUpdated::class, 2);
    Exceptions::assertReported(CredentialsRejected::class);
});

it('flags the cookie when a sync cannot reach YouTube Music', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);
    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeProvider()->shouldFail = true;

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    expect($account->refresh()->hasExpiredCookie())->toBeTrue();
});

it('clears the cookie flag once a sync succeeds', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);
    $account = YouTubeMusicAccount::factory()->expired()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeProvider()->playlists = [FakeProviderAdapter::aPlaylistSummary(id: 'PL1')];
    $this->fakeProvider()->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1');

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    expect($account->refresh()->hasExpiredCookie())->toBeFalse();
});

it('releases itself when the call budget is spent, without flagging the cookie', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);
    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeProvider()->rateLimitedFor = 42;

    $job = (new SyncYouTubeMusicLibrary($sync->id, $account->id))->withFakeQueueInteractions();
    $job->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    $job->assertReleased(delay: 42);
    expect($sync->refresh()->status)->not->toBe(YouTubeMusicSyncStatus::Failed)
        ->and($account->refresh()->hasExpiredCookie())->toBeFalse();
});

it('fails a sync left running when the job gives up', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);
    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')
        ->create(['status' => YouTubeMusicSyncStatus::Syncing]);

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))->failed(new RuntimeException('Timed out.'));

    expect($sync->refresh()->status)->toBe(YouTubeMusicSyncStatus::Failed)
        ->and($sync->finished_at)->not->toBeNull();
});

it('fails without expiring the cookie when YouTube Music cannot be reached', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);

    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeProvider()->unavailable = true;

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    expect($sync->refresh()->status)->toBe(YouTubeMusicSyncStatus::Failed)
        ->and($sync->error_message)->toBe('unavailable')
        ->and($account->refresh()->cookie_expired_at)->toBeNull();
});

it('stores the failure as a code when the cookie is refused', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);

    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeProvider()->shouldFail = true;

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))
        ->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    expect($sync->refresh()->error_message)->toBe('credentials_rejected')
        ->and($account->refresh()->cookie_expired_at)->not->toBeNull();
});
