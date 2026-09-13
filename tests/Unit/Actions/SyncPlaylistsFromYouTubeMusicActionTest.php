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
