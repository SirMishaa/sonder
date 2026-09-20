<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Broadcasting\BroadcastManager;

/**
 * routes/channels.php's authorization callback only ever gets exercised in
 * this suite: BROADCAST_CONNECTION=null in phpunit.xml means the null
 * broadcaster never even reaches it (retrieveUser()/verifyUserCanAccessChannel()
 * are Mercure/Pusher-driver concerns), so these tests force the real
 * "mercure" driver for the duration of each test.
 *
 * Forcing the driver mid-test requires two steps, not one:
 *   1. forgetDrivers() drops the cached null-driver instance so the next
 *      Broadcast:: call re-resolves against the new config.
 *   2. That fresh driver instance starts with an empty channel registry —
 *      the real youtube-music-sync.{syncId} channel was only ever registered
 *      on the discarded null-driver instance at boot — so routes/channels.php
 *      must be required again to re-register it against the new driver.
 * Skipping step 2 makes every channel look unrecognized and every request
 * denied, owner included; skipping step 1 leaves the null driver active and
 * this test would pass or fail for the wrong reason entirely.
 */
beforeEach(function (): void {
    config([
        'broadcasting.default' => 'mercure',
        'broadcasting.connections.mercure.url' => 'http://localhost:8004/.well-known/mercure',
        'broadcasting.connections.mercure.secret' => str_repeat('a', 32),
        'broadcasting.connections.mercure.cookie_name' => 'mercure_access_token',
    ]);

    app(BroadcastManager::class)->forgetDrivers();

    require base_path('routes/channels.php');
});

it('authorizes the sync owner to subscribe', function (): void {
    $owner = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($owner)->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    $response = $this->actingAs($owner)->postJson('/broadcasting/auth', [
        'channel_names' => ['private-youtube-music-sync.'.$sync->id],
    ]);

    $response->assertOk();
    expect($response->json('channel_names.0'))->not->toHaveKey('denied');
});

it('denies a user who does not own the sync', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($owner)->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    $response = $this->actingAs($intruder)->postJson('/broadcasting/auth', [
        'channel_names' => ['private-youtube-music-sync.'.$sync->id],
    ]);

    $response->assertOk();
    expect($response->json('channel_names.0.denied'))->toBeTrue();
});

it('denies a guest', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    $response = $this->postJson('/broadcasting/auth', [
        'channel_names' => ['private-youtube-music-sync.'.$sync->id],
    ]);

    $response->assertOk();
    expect($response->json('channel_names.0.denied'))->toBeTrue();
});
