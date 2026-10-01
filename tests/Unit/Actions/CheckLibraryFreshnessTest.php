<?php

declare(strict_types=1);

use App\Actions\CheckLibraryFreshness;
use App\Enums\YouTubeMusicSyncStatus;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Tests\Support\FakeProviderAdapter;

beforeEach(function (): void {
    $this->account = YouTubeMusicAccount::factory()->create();
    Playlist::factory()->for($this->account, 'youtubeMusicAccount')->create(['last_checked_at' => now()->subDays(2)]);
    $this->fakeProvider()->playlists = [FakeProviderAdapter::aPlaylistSummary(id: 'PL1')];
    $this->fakeProvider()->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1');
});

it('starts a sync when the last attempt is over an hour old', function (): void {
    YouTubeMusicSync::factory()->for($this->account, 'youtubeMusicAccount')
        ->create(['status' => YouTubeMusicSyncStatus::Completed, 'created_at' => now()->subHours(2)]);

    resolve(CheckLibraryFreshness::class)->handle($this->account);

    expect(YouTubeMusicSync::query()->count())->toBe(2);
});

it('does not start another sync after a recent attempt that left the playlists unchecked', function (YouTubeMusicSyncStatus $status): void {
    YouTubeMusicSync::factory()->for($this->account, 'youtubeMusicAccount')
        ->create(['status' => $status, 'created_at' => now()->subMinutes(5)]);

    $sync = resolve(CheckLibraryFreshness::class)->handle($this->account);

    expect($sync)->toBeNull()
        ->and(YouTubeMusicSync::query()->count())->toBe(1);
})->with([YouTubeMusicSyncStatus::Completed, YouTubeMusicSyncStatus::Failed]);

it('returns the sync already running instead of starting one', function (): void {
    $running = YouTubeMusicSync::factory()->for($this->account, 'youtubeMusicAccount')
        ->create(['status' => YouTubeMusicSyncStatus::Syncing, 'created_at' => now()->subHours(2)]);

    $sync = resolve(CheckLibraryFreshness::class)->handle($this->account);

    expect($sync?->id)->toBe($running->id)
        ->and(YouTubeMusicSync::query()->count())->toBe(1);
});

it('leaves an expired cookie alone until the user replaces it', function (): void {
    $this->account->markCookieExpired();

    $sync = resolve(CheckLibraryFreshness::class)->handle($this->account);

    expect($sync)->toBeNull()
        ->and(YouTubeMusicSync::query()->count())->toBe(0);
});

it('retries a recent sync that was interrupted instead of waiting an hour', function (): void {
    $orphan = YouTubeMusicSync::factory()->for($this->account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(6),
    ]);

    $sync = resolve(CheckLibraryFreshness::class)->handle($this->account);

    expect($sync?->id)->not->toBe($orphan->id)
        ->and($orphan->refresh()->status)->toBe(YouTubeMusicSyncStatus::Failed);
});
