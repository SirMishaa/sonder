<?php

declare(strict_types=1);

use App\Actions\SyncPlaylistsFromYouTubeMusicAction;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;
use Tests\Support\FakeYouTubeMusicClient;

it('upserts playlists and tracks from YouTube Music', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus', trackCount: 1),
    ];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus', trackCount: 1);

    resolve(SyncPlaylistsFromYouTubeMusicAction::class)->handle($account);

    $playlist = Playlist::query()->where('youtube_playlist_id', 'PL1')->firstOrFail();

    expect($playlist->title)->toBe('Deep Focus')
        ->and($playlist->tracks()->count())->toBe(1);
});

it('replaces the tracks of a playlist synced a second time', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1')];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', trackCount: 1);

    $action = resolve(SyncPlaylistsFromYouTubeMusicAction::class);
    $action->handle($account);
    $action->handle($account);

    $playlist = Playlist::query()->where('youtube_playlist_id', 'PL1')->firstOrFail();

    expect(Playlist::query()->count())->toBe(1)
        ->and($playlist->tracks()->count())->toBe(1);
});

it('reports progress after each playlist, with the total known up front', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', title: 'Deep Focus'),
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL2', title: 'Gaming'),
    ];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', title: 'Deep Focus');
    $this->fakeYouTubeMusic()->tracks['PL2'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL2', title: 'Gaming');

    $calls = [];

    resolve(SyncPlaylistsFromYouTubeMusicAction::class)->handle(
        $account,
        function (int $synced, int $total, ?Playlist $playlist) use (&$calls): void {
            $calls[] = [$synced, $total, $playlist?->title];
        },
    );

    expect($calls)->toBe([
        [0, 2, null],
        [1, 2, 'Deep Focus'],
        [2, 2, 'Gaming'],
    ]);
});

it('skips downloading the tracks of a playlist whose listing did not change', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1')];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');

    $action = resolve(SyncPlaylistsFromYouTubeMusicAction::class);
    $action->handle($account);
    $this->travel(2)->hours();
    $action->handle($account);

    $playlist = Playlist::query()->where('youtube_playlist_id', 'PL1')->firstOrFail();

    expect($this->fakeYouTubeMusic()->callCount('playlist'))->toBe(1)
        ->and($playlist->last_checked_at->isSameMinute(now()))->toBeTrue();
});

it('downloads the tracks again once the listing changes', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', trackCount: 1)];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');

    $action = resolve(SyncPlaylistsFromYouTubeMusicAction::class);
    $action->handle($account);

    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1', trackCount: 2)];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1', trackCount: 2, tracks: [
        FakeYouTubeMusicClient::aTrack('One', 'VID_A'),
        FakeYouTubeMusicClient::aTrack('Two', 'VID_B'),
    ]);
    $action->handle($account);

    $playlist = Playlist::query()->where('youtube_playlist_id', 'PL1')->firstOrFail();

    expect($this->fakeYouTubeMusic()->callCount('playlist'))->toBe(2)
        ->and($playlist->tracks()->count())->toBe(2);
});

it('flags a playlist that left the library without deleting it', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1'),
        FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL2'),
    ];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');
    $this->fakeYouTubeMusic()->tracks['PL2'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL2');

    $action = resolve(SyncPlaylistsFromYouTubeMusicAction::class);
    $action->handle($account);

    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1')];
    $action->handle($account);

    $removed = Playlist::query()->where('youtube_playlist_id', 'PL2')->firstOrFail();

    expect($removed->removed_at)->not->toBeNull()
        ->and($removed->tracks()->count())->toBe(1)
        ->and(Playlist::query()->where('youtube_playlist_id', 'PL1')->value('removed_at'))->toBeNull();
});

it('clears the flag when a removed playlist comes back', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $playlist = Playlist::factory()->for($account, 'youtubeMusicAccount')->removed()->create(['youtube_playlist_id' => 'PL1']);
    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1')];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');

    resolve(SyncPlaylistsFromYouTubeMusicAction::class)->handle($account);

    expect($playlist->refresh()->removed_at)->toBeNull();
});

it('never flags anything when YouTube Music returns an empty library', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $playlist = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeYouTubeMusic()->playlists = [];

    resolve(SyncPlaylistsFromYouTubeMusicAction::class)->handle($account);

    expect($playlist->refresh()->removed_at)->toBeNull();
});
