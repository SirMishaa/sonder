<?php

declare(strict_types=1);

use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

it('shares the connected library with every page for the sidebar', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create(['account_name' => 'Mishaa']);
    Playlist::factory()->for($account, 'youtubeMusicAccount')->create(['youtube_playlist_id' => 'PL_KEPT']);
    Playlist::factory()->for($account, 'youtubeMusicAccount')->removed()->create(['youtube_playlist_id' => 'PL_GONE']);

    $response = $this->actingAs($user)->get(route('user-profile.edit'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('library.accountName', 'Mishaa')
        ->where('library.activeSync', null)
        ->where('library.playlists', fn (Collection $playlists): bool => $playlists->pluck('isRemoved', 'id')->sortKeys()->all() === ['PL_GONE' => true, 'PL_KEPT' => false]));
});

it('shares no library when nothing is connected', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('user-profile.edit'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->where('library', null));
});
