<?php

declare(strict_types=1);

use App\Actions\SyncPlaylistTracks;
use App\Data\TrackData;
use App\Models\Playlist;
use Tests\Support\FakeYouTubeMusicClient;

/**
 * @param  array<int, TrackData>  $tracks
 */
function syncTracks(Playlist $playlist, array $tracks): bool
{
    return resolve(SyncPlaylistTracks::class)->handle(
        $playlist,
        FakeYouTubeMusicClient::aPlaylist(tracks: $tracks),
    );
}

/**
 * @return array<int, string|null>
 */
function storedVideoIds(Playlist $playlist): array
{
    return $playlist->tracks()->pluck('youtube_video_id')->all();
}

it('keeps the row of a track that is still in the playlist', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [FakeYouTubeMusicClient::aTrack('Rendezvous', 'VID_A')]);
    $originalId = $playlist->tracks()->value('id');

    syncTracks($playlist, [
        FakeYouTubeMusicClient::aTrack('Rendezvous (Remastered)', 'VID_A'),
        FakeYouTubeMusicClient::aTrack('Nouveau', 'VID_B'),
    ]);

    $kept = $playlist->tracks()->where('youtube_video_id', 'VID_A')->firstOrFail();

    expect($kept->id)->toBe($originalId)
        ->and($kept->title)->toBe('Rendezvous (Remastered)')
        ->and(storedVideoIds($playlist))->toBe(['VID_A', 'VID_B']);
});

it('removes tracks that left the playlist', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [
        FakeYouTubeMusicClient::aTrack('One', 'VID_A'),
        FakeYouTubeMusicClient::aTrack('Two', 'VID_B'),
    ]);

    $changed = syncTracks($playlist, [FakeYouTubeMusicClient::aTrack('Two', 'VID_B')]);

    expect($changed)->toBeTrue()
        ->and(storedVideoIds($playlist))->toBe(['VID_B']);
});

it('reorders tracks in place instead of recreating them', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [
        FakeYouTubeMusicClient::aTrack('One', 'VID_A'),
        FakeYouTubeMusicClient::aTrack('Two', 'VID_B'),
    ]);
    $ids = $playlist->tracks()->pluck('id', 'youtube_video_id')->all();

    syncTracks($playlist, [
        FakeYouTubeMusicClient::aTrack('Two', 'VID_B'),
        FakeYouTubeMusicClient::aTrack('One', 'VID_A'),
    ]);

    expect(storedVideoIds($playlist))->toBe(['VID_B', 'VID_A'])
        ->and($playlist->tracks()->pluck('id', 'youtube_video_id')->all())->toEqual($ids);
});

it('matches repeated copies of the same video one by one', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [
        FakeYouTubeMusicClient::aTrack('Loop', 'VID_A'),
        FakeYouTubeMusicClient::aTrack('Loop', 'VID_A'),
    ]);
    $firstCopyId = $playlist->tracks()->value('id');

    syncTracks($playlist, [FakeYouTubeMusicClient::aTrack('Loop', 'VID_A')]);

    expect($playlist->tracks()->pluck('id')->all())->toBe([$firstCopyId]);
});

it('matches a track without a video id by its title and artists', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [FakeYouTubeMusicClient::aTrack('Private upload', null)]);
    $originalId = $playlist->tracks()->value('id');

    $changed = syncTracks($playlist, [FakeYouTubeMusicClient::aTrack('Private upload', null)]);

    expect($changed)->toBeFalse()
        ->and($playlist->tracks()->pluck('id')->all())->toBe([$originalId]);
});

it('records when the playlist content last changed', function (): void {
    $this->freezeSecond();
    $playlist = Playlist::factory()->create(['last_changed_at' => null]);

    syncTracks($playlist, [FakeYouTubeMusicClient::aTrack('One', 'VID_A')]);

    expect($playlist->refresh()->last_changed_at)->toEqual(now());
});

it('reports no change and keeps the change date when nothing moved', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [FakeYouTubeMusicClient::aTrack('One', 'VID_A')]);
    $changedAt = $playlist->refresh()->last_changed_at;

    $this->travel(1)->day();
    $changed = syncTracks($playlist, [FakeYouTubeMusicClient::aTrack('One', 'VID_A')]);

    expect($changed)->toBeFalse()
        ->and($playlist->refresh()->last_changed_at)->toEqual($changedAt);
});
