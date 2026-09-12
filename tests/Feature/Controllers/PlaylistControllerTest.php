<?php

declare(strict_types=1);

use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
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

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('playlist/Index')
            ->where('accountName', 'Mishaa')
            ->has('playlists', 2)
            ->where('playlists.0.title', 'Deep Focus'));

    expect(Playlist::query()->count())->toBe(2);
});

it('sends a user with no connection to the connection page', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('playlist.index'));

    $response->assertRedirectToRoute('youtube-music-connection.create');
});

it('sends the user back to reconnect when the cookie stopped working', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $this->fakeYouTubeMusic()->shouldFail = true;

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertRedirectToRoute('youtube-music-connection.create')
        ->assertSessionHasErrors('cookie');
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
