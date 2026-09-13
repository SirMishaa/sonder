<?php

declare(strict_types=1);

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Database\Factories\YouTubeMusicAccountFactory;

it('renders the connection page', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('youtube-music-connection.create'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('youtube-music-connection/Create')
            ->where('account', null));
});

it('shows the connected account without leaking the cookie', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create(['account_name' => 'Mishaa']);

    $response = $this->actingAs($user)->get(route('youtube-music-connection.create'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('account.account_name', 'Mishaa')
            ->missing('account.cookie'));
});

it('connects an account and starts a sync', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->fromRoute('youtube-music-connection.create')
        ->post(route('youtube-music-connection.store'), [
            'cookie' => YouTubeMusicAccountFactory::cookie(),
        ]);

    $account = $user->youTubeMusicAccount()->first();
    expect($account)->not->toBeNull();

    $sync = YouTubeMusicSync::query()->where('youtube_music_account_id', $account->id)->firstOrFail();

    $response->assertRedirectToRoute('youtube-music-connection.sync', $sync);
});

it('renders the sync page', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create([
        'status' => YouTubeMusicSyncStatus::Syncing,
        'total_playlists' => 4,
        'synced_playlists' => 2,
        'current_playlist_title' => 'Deep Focus',
    ]);

    $response = $this->actingAs($user)->get(route('youtube-music-connection.sync', $sync));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('youtube-music-connection/Sync')
            ->where('sync.id', $sync->id)
            ->where('sync.status', 'syncing')
            ->where('sync.totalPlaylists', 4)
            ->where('sync.syncedPlaylists', 2)
            ->where('sync.currentPlaylistTitle', 'Deep Focus'));
});

it('refuses to show another user\'s sync', function (): void {
    $owner = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($owner)->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('youtube-music-connection.sync', $sync));

    $response->assertNotFound();
});

it('reports a cookie that YouTube Music refuses', function (): void {
    $user = User::factory()->create();
    $this->fakeYouTubeMusic()->shouldFail = true;

    $response = $this->actingAs($user)
        ->fromRoute('youtube-music-connection.create')
        ->post(route('youtube-music-connection.store'), [
            'cookie' => YouTubeMusicAccountFactory::cookie(),
        ]);

    $response->assertRedirect()->assertSessionHasErrors('cookie');

    expect(YouTubeMusicAccount::query()->count())->toBe(0);
});

it('rejects a cookie missing the required parts', function (string $cookie): void {
    $response = $this->actingAs(User::factory()->create())
        ->fromRoute('youtube-music-connection.create')
        ->post(route('youtube-music-connection.store'), ['cookie' => $cookie]);

    $response->assertSessionHasErrors('cookie');

    expect(YouTubeMusicAccount::query()->count())->toBe(0);
})->with([
    'empty' => '',
    'unrelated text' => 'hello world',
    'missing SID' => '__Secure-3PAPISID=aaa; SAPISID=bbb',
    'missing SAPISID' => '__Secure-3PAPISID=aaa; SID=ccc',
    'missing 3PAPISID' => 'SAPISID=bbb; SID=ccc',
]);

it('disconnects the account', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->fromRoute('youtube-music-connection.create')
        ->delete(route('youtube-music-connection.destroy'));

    $response->assertRedirectToRoute('youtube-music-connection.create');

    expect(YouTubeMusicAccount::query()->count())->toBe(0);
});

it('tolerates disconnecting when nothing is connected', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->fromRoute('youtube-music-connection.create')
        ->delete(route('youtube-music-connection.destroy'));

    $response->assertRedirectToRoute('youtube-music-connection.create');
});

it('keeps guests out', function (): void {
    $this->get(route('youtube-music-connection.create'))->assertRedirect(route('login'));
    $this->post(route('youtube-music-connection.store'))->assertRedirect(route('login'));
    $this->delete(route('youtube-music-connection.destroy'))->assertRedirect(route('login'));
});
