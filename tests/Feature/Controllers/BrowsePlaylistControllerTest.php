<?php

declare(strict_types=1);

use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeProviderAdapter;

it('renders a public playlist', function (): void {
    $this->fakeProvider()->publicPlaylists['PL_PUBLIC'] = FakeProviderAdapter::aPlaylist('PL_PUBLIC', 'Road Trip');

    $this->actingAs(User::factory()->create())->get(route('browse-playlist.show', 'PL_PUBLIC'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('browse-playlist/Show')
            ->where('page.playlist.title', 'Road Trip')
            ->has('page.tracks', 1)
            ->where('page.tracks.0.inLibrary', false));
});

it('redirects a public playlist already in the library to its library page', function (): void {
    $user = User::factory()->create();
    Playlist::factory()->for(YouTubeMusicAccount::factory()->for($user), 'youtubeMusicAccount')->create(['youtube_playlist_id' => 'PL_PUBLIC']);

    $this->actingAs($user)->get(route('browse-playlist.show', 'PL_PUBLIC'))
        ->assertRedirectToRoute('playlist.show', 'PL_PUBLIC');

    expect($this->fakeProvider()->calls)->toBeEmpty();
});

it('shows a catalog error instead of failing', function (): void {
    $this->fakeProvider()->unavailable = true;

    $this->actingAs(User::factory()->create())->get(route('browse-playlist.show', 'PL_PUBLIC'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page', null)
            ->where('catalogError', fn ($message): bool => is_string($message) && $message !== ''));
});
