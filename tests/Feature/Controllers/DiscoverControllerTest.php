<?php

declare(strict_types=1);

use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeProviderAdapter;

it('renders discover with library stats and fresh finds loaded after the page', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    // The page starts a freshness sync; it is incidental here, so make it fail
    // rather than read an empty fake library as "every playlist was removed".
    $this->fakeProvider()->unavailable = true;
    $playlist = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $playlist->tracks()->create([
        'youtube_video_id' => 'VID_A',
        'title' => 'Sonne',
        'artists' => 'Rammstein',
        'thumbnail_url' => 'https://i.ytimg.com/vi/VID_A/hqdefault.jpg',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('discover/Index')
        ->missing('stats')
        ->missing('freshFinds')
        ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
            ->where('stats.trackCount', 1)
            ->where('freshFinds.0.track.videoId', 'VID_A')));
});

it('sends a user with no connection to the connection page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirectToRoute('youtube-music-connection.create');
});

it('sends a newly connected user to the blocking first sync', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $this->fakeProvider()->playlists = [FakeProviderAdapter::aPlaylistSummary(id: 'PL1')];
    $this->fakeProvider()->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirectToRoute('youtube-music-connection.sync', YouTubeMusicSync::query()->sole());
});

it('keeps guests out', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('shows guests the landing page at the root', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('welcome/Index'));
});

it('sends a signed-in user from the landing page to discover', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirectToRoute('dashboard');
});
