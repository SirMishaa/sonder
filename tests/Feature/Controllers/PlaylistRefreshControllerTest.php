<?php

declare(strict_types=1);

use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Tests\Support\FakeYouTubeMusicClient;

/**
 * @param  array<string, mixed>  $attributes
 */
function refreshablePlaylist(User $user, array $attributes = []): Playlist
{
    $account = YouTubeMusicAccount::factory()->for($user)->create();

    return Playlist::factory()->for($account, 'youtubeMusicAccount')->create([
        'youtube_playlist_id' => 'PL1',
        'fingerprint' => 'unchanged',
        ...$attributes,
    ]);
}

it('re-reads the tracks even though the listing did not change', function (): void {
    $user = User::factory()->create();
    $playlist = refreshablePlaylist($user);
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', tracks: [
        FakeYouTubeMusicClient::aTrack('Swapped in', 'VID_NEW'),
    ]);

    $response = $this->actingAs($user)
        ->from(route('playlist.show', 'PL1'))
        ->post(route('playlist-refresh.store', 'PL1'));

    $response->assertRedirect(route('playlist.show', 'PL1'));
    expect($playlist->tracks()->pluck('youtube_video_id')->all())->toBe(['VID_NEW'])
        ->and($playlist->refresh()->fingerprint)->toBe('unchanged');
});

it('does not let a user refresh another user playlist', function (): void {
    refreshablePlaylist(User::factory()->create());

    $response = $this->actingAs(User::factory()->create())
        ->post(route('playlist-refresh.store', 'PL1'));

    $response->assertNotFound();
    expect($this->fakeYouTubeMusic()->callCount('playlist'))->toBe(0);
});

it('refuses to refresh a playlist that left the library', function (): void {
    $user = User::factory()->create();
    refreshablePlaylist($user, ['removed_at' => now()]);

    $response = $this->actingAs($user)->post(route('playlist-refresh.store', 'PL1'));

    $response->assertSessionHasErrors('refresh');
    expect($this->fakeYouTubeMusic()->callCount('playlist'))->toBe(0);
});

it('reports a YouTube Music failure instead of crashing', function (): void {
    $user = User::factory()->create();
    refreshablePlaylist($user);
    $this->fakeYouTubeMusic()->shouldFail = true;

    $response = $this->actingAs($user)->post(route('playlist-refresh.store', 'PL1'));

    $response->assertSessionHasErrors('refresh');
});

it('sends guests to the login page', function (): void {
    $response = $this->post(route('playlist-refresh.store', 'PL1'));

    $response->assertRedirectToRoute('login');
});

it('tells the user to wait when the call budget is spent', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeYouTubeMusic()->rateLimitedFor = 42;

    $response = $this->actingAs($user)
        ->fromRoute('playlist.show', $playlist->youtube_playlist_id)
        ->post(route('playlist-refresh.store', $playlist->youtube_playlist_id));

    $response->assertSessionHasErrors(['refresh' => 'Sonder is pacing its calls to YouTube Music. Try again in 42 seconds.']);
    expect($account->refresh()->hasExpiredCookie())->toBeFalse();
});
