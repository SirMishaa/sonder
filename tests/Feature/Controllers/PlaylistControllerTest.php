<?php

declare(strict_types=1);

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\Playlist;
use App\Models\RecordingResolution;
use App\Models\Tag;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeProviderAdapter;

it('lists the playlists of the connected account', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create(['account_name' => 'Mishaa']);
    $this->fakeProvider()->playlists = [
        FakeProviderAdapter::aPlaylistSummary(id: 'PL1', title: 'Deep Focus'),
        FakeProviderAdapter::aPlaylistSummary(id: 'PL2', title: 'Gaming'),
    ];
    $this->fakeProvider()->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1', title: 'Deep Focus');
    $this->fakeProvider()->tracks['PL2'] = FakeProviderAdapter::aPlaylist(id: 'PL2', title: 'Gaming');

    // First visit: nothing synced yet, redirects to the blocking sync page.
    $this->actingAs($user)->get(route('playlist.index'))
        ->assertRedirectToRoute('youtube-music-connection.sync', YouTubeMusicSync::query()->firstOrFail());

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('playlist/Index')
            ->where('library.accountName', 'Mishaa')
            ->where('library.activeSync', null)
            ->has('library.playlists', 2)
            ->where('library.playlists.0.title', 'Deep Focus'));
});

it('sends a user with no connection to the connection page', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('playlist.index'));

    $response->assertRedirectToRoute('youtube-music-connection.create');
});

it('redirects to the sync page and fails the sync when the cookie stopped working', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $this->fakeProvider()->shouldFail = true;

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
    $this->fakeProvider()->playlists = [FakeProviderAdapter::aPlaylistSummary(id: 'PL1')];
    $this->fakeProvider()->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1');

    $response = $this->actingAs($user)->get(route('playlist.index'));

    // The sync queue driver runs the job inline, so the sync has already
    // finished by the time the page renders; what matters is that the page
    // rendered instead of redirecting to the blocking sync screen.
    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('playlist/Index'));
    expect(YouTubeMusicSync::query()->sole()->status)->toBe(YouTubeMusicSyncStatus::Completed);
});

it('renders the playlist with tracks from the database', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    $fake = $this->fakeProvider();
    $fake->playlists = [FakeProviderAdapter::aPlaylistSummary(id: 'PL1', title: 'Deep Focus')];
    $fake->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1', title: 'Deep Focus');

    $this->actingAs($user)->get(route('playlist.index'));

    $response = $this->actingAs($user)->get(route('playlist.show', 'PL1'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
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
    $fake = $this->fakeProvider();
    $fake->playlists = [FakeProviderAdapter::aPlaylistSummary(id: 'PL1', trackCount: 42)];
    $fake->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1');

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
    // The page starts a freshness sync; it is incidental here, so make it fail
    // rather than read an empty fake library as "every playlist was removed".
    $this->fakeProvider()->unavailable = true;

    $response = $this->actingAs($user)->get(route('playlist.index'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('library.playlists', fn (Collection $playlists): bool => $playlists->where('isRemoved', true)->pluck('id')->values()->all() === ['PL_GONE'])
        ->where('library.lastCheckedAt', now()->toIso8601String()));
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

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('syncState.lastCheckedAt', now()->toIso8601String())
        ->where('syncState.lastChangedAt', now()->subDay()->toIso8601String())
        ->where('syncState.removedAt', now()->toIso8601String()));
});

it('loads suggestions from the rest of the library after the page', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    $current = Playlist::factory()->for($account, 'youtubeMusicAccount')->create(['youtube_playlist_id' => 'PL1']);
    $other = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $current->tracks()->create(['youtube_video_id' => 'VID_IN', 'title' => 'Already here', 'artists' => 'A', 'thumbnail_url' => 'https://i.ytimg.com/vi/VID_IN/0.jpg']);
    $other->tracks()->create(['youtube_video_id' => 'VID_IN', 'title' => 'Already here', 'artists' => 'A', 'thumbnail_url' => 'https://i.ytimg.com/vi/VID_IN/0.jpg']);
    $other->tracks()->create(['youtube_video_id' => 'VID_NEW', 'title' => 'New to it', 'artists' => 'B', 'thumbnail_url' => 'https://i.ytimg.com/vi/VID_NEW/0.jpg']);

    $response = $this->actingAs($user)->get(route('playlist.show', 'PL1'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->missing('suggestionPool')
        ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
            ->has('suggestionPool', 1)
            ->where('suggestionPool.0.track.videoId', 'VID_NEW')));
});

it('shows the genres of each track', function (): void {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for(YouTubeMusicAccount::factory()->for($user), 'youtubeMusicAccount')->create(['youtube_playlist_id' => 'PL1']);
    libraryTrack($playlist, 'video000001');
    libraryTrack($playlist, 'video000002', 'Uprising');
    RecordingResolution::factory()->create(['external_id' => 'video000001'])->recording()->firstOrFail()
        ->tags()->attach(Tag::named('Alternative Rock', isGenre: true), ['source' => 'musicbrainz', 'weight' => 90]);

    $this->actingAs($user)->get(route('playlist.show', 'PL1'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('playlist.tracks.0.genres', ['alternative rock'])
            ->where('playlist.tracks.1.genres', []));
});
