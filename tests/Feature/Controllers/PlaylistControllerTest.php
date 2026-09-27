<?php

declare(strict_types=1);

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Tests\Support\FakeYouTubeMusicClient;

it('lists the playlists of the connected account', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create(['account_name' => 'Mishaa']);
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus'),
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL2', title: 'Gaming'),
    ];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus');
    $this->fakeYouTubeMusic()->tracks['PL2'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL2', title: 'Gaming');

    // First visit: nothing synced yet, redirects to the blocking sync page.
    $this->actingAs($user)->get(route('playlist.index'))
        ->assertRedirectToRoute('youtube-music-connection.sync', YouTubeMusicSync::query()->firstOrFail());

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('playlist/Index')
            ->where('accountName', 'Mishaa')
            ->where('activeSync', null)
            ->has('playlists', 2)
            ->where('playlists.0.title', 'Deep Focus'));
});

it('sends a user with no connection to the connection page', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('playlist.index'));

    $response->assertRedirectToRoute('youtube-music-connection.create');
});

it('redirects to the sync page and fails the sync when the cookie stopped working', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $this->fakeYouTubeMusic()->shouldFail = true;

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertRedirectToRoute('youtube-music-connection.sync', YouTubeMusicSync::query()->firstOrFail());
    expect(YouTubeMusicSync::query()->firstOrFail()->status)->toBe(YouTubeMusicSyncStatus::Failed);
});

it('refreshes stale playlists in the background instead of blocking', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    Playlist::factory()->for($account, 'youtubeMusicAccount')->create([
        'last_checked_at' => now()->subDays(2),
    ]);
    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1')];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('playlist/Index')
            ->has('activeSync')
            ->where('activeSync.status', 'completed'));
});

it('renders the playlist with tracks from the database', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    $fake = $this->fakeYouTubeMusic();
    $fake->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus')];
    $fake->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus');

    $this->actingAs($user)->get(route('playlist.index'));

    $response = $this->actingAs($user)->get(route('playlist.show', 'PL1'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('playlist/Show')
            ->where('playlistId', 'PL1')
            ->where('summary.title', 'Deep Focus')
            ->where('playlist.title', 'Deep Focus')
            ->has('playlist.tracks', 1)
            ->where('playlist.tracks.0.artists', 'Ludwig Göransson'));
});

it('redirects when playlist is not in the database', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();

    $response = $this->actingAs($user)->get(route('playlist.show', 'PL_UNLISTED'));

    $response->assertRedirectToRoute('playlist.index');
});

it('syncs playlists from YouTube Music to the database', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    $fake = $this->fakeYouTubeMusic();
    $fake->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', trackCount: 42)];
    $fake->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');

    $this->actingAs($user)->get(route('playlist.index'));

    $playlist = Playlist::query()->where('youtube_playlist_id', 'PL1')->firstOrFail();

    expect($playlist->track_count)->toBe(42)
        ->and($playlist->tracks()->count())->toBe(1);
});

it('redirects to playlist index when playlist not found', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();

    $response = $this->actingAs($user)->get(route('playlist.show', 'PL1'));

    $response->assertRedirectToRoute('playlist.index');
});

it('sends a user with no connection away from a playlist', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('playlist.show', 'PL1'));

    $response->assertRedirectToRoute('youtube-music-connection.create');
});

it('keeps guests out', function (): void {
    $this->get(route('playlist.index'))->assertRedirect(route('login'));
    $this->get(route('playlist.show', 'PL1'))->assertRedirect(route('login'));
});

it('tells the index which playlists left the library and when it was last checked', function (): void {
    $this->freezeSecond();
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    Playlist::factory()->for($account, 'youtubeMusicAccount')->create(['youtube_playlist_id' => 'PL_KEPT']);
    Playlist::factory()->for($account, 'youtubeMusicAccount')->removed()->create(['youtube_playlist_id' => 'PL_GONE']);

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('removedPlaylistIds', ['PL_GONE'])
        ->where('lastCheckedAt', now()->toIso8601String()));
});

it('renders the sync state of a playlist', function (): void {
    $this->freezeSecond();
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    Playlist::factory()->for($account, 'youtubeMusicAccount')->removed()->create([
        'youtube_playlist_id' => 'PL1',
        'last_changed_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->get(route('playlist.show', 'PL1'));

    $response->assertInertia(fn ($page) => $page
        ->where('syncState.lastCheckedAt', now()->toIso8601String())
        ->where('syncState.lastChangedAt', now()->subDay()->toIso8601String())
        ->where('syncState.removedAt', now()->toIso8601String()));
});
