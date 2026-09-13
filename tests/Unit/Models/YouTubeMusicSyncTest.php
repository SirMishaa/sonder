<?php

declare(strict_types=1);

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;

it('belongs to a youtube music account', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    expect($sync->youtubeMusicAccount->id)->toBe($account->id);
});

it('defaults to pending with zero playlists synced', function (): void {
    $sync = YouTubeMusicSync::factory()->create();

    expect($sync->status)->toBe(YouTubeMusicSyncStatus::Pending)
        ->and($sync->synced_playlists)->toBe(0)
        ->and($sync->total_playlists)->toBeNull();
});

it('knows whether a user owns it, through its account', function (): void {
    $owner = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($owner)->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    expect($sync->isOwnedBy($owner))->toBeTrue()
        ->and($sync->isOwnedBy(User::factory()->create()))->toBeFalse();
});

it('goes away with its account', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    $account->delete();

    expect(YouTubeMusicSync::query()->count())->toBe(0);
});
