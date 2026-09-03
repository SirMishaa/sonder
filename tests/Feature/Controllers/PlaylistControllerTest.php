<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Tests\Support\FakeYouTubeMusicClient;

it('lists the playlists of the connected account', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create(['account_name' => 'Mishaa']);
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus'),
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL2', title: 'Gaming'),
    ];

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('playlist/Index')
            ->where('accountName', 'Mishaa')
            ->has('playlists', 2)
            ->where('playlists.0.title', 'Deep Focus'));
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

it('renders the playlist shell before the tracks arrive', function (): void {
    // The shell comes from the cached summary; the tracks are deferred because
    // they cost a chain of continuation requests.
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $fake = $this->fakeYouTubeMusic();
    $fake->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus')];
    $fake->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus');

    $response = $this->actingAs($user)->get(route('playlist.show', 'PL1'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('playlist/Show')
            ->where('playlistId', 'PL1')
            ->where('summary.title', 'Deep Focus')
            ->missing('playlist')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('playlist.title', 'Deep Focus')
                ->has('playlist.tracks', 1)
                ->where('playlist.tracks.0.artists', 'Ludwig Göransson')));
});

it('renders a playlist that is absent from the library listing', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $fake = $this->fakeYouTubeMusic();
    $fake->tracks['PL_UNLISTED'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL_UNLISTED');

    $response = $this->actingAs($user)->get(route('playlist.show', 'PL_UNLISTED'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary', null));
});

it('passes the known track count down so the cache can key on it', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $fake = $this->fakeYouTubeMusic();
    $fake->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', trackCount: 42)];
    $fake->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');

    $this->actingAs($user)
        ->get(route('playlist.show', 'PL1'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->has('playlist')));

    $call = collect($fake->calls)->firstWhere('method', 'playlist');

    expect($call)->not->toBeNull()
        ->and($call['trackCount'])->toBe(42);
});

it('sends the user back to reconnect when a playlist page finds a dead cookie', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $this->fakeYouTubeMusic()->shouldFail = true;

    $response = $this->actingAs($user)->get(route('playlist.show', 'PL1'));

    $response->assertRedirectToRoute('youtube-music-connection.create')
        ->assertSessionHasErrors('cookie');
});

it('sends a user with no connection away from a playlist', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('playlist.show', 'PL1'));

    $response->assertRedirectToRoute('youtube-music-connection.create');
});

it('keeps guests out', function (): void {
    $this->get(route('playlist.index'))->assertRedirect(route('login'));
    $this->get(route('playlist.show', 'PL1'))->assertRedirect(route('login'));
});
