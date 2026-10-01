<?php

declare(strict_types=1);

use App\Actions\SyncPlaylistTracks;
use App\Models\Playlist;
use App\Services\Music\Data\RemoteTrack;
use Tests\Support\FakeProviderAdapter;

/**
 * @param  list<RemoteTrack>  $tracks
 */
function syncTracks(Playlist $playlist, array $tracks): bool
{
    return resolve(SyncPlaylistTracks::class)->handle(
        $playlist,
        FakeProviderAdapter::aPlaylist(tracks: $tracks),
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
    syncTracks($playlist, [FakeProviderAdapter::aTrack('Rendezvous', 'VID_A')]);
    $originalId = $playlist->tracks()->value('id');

    syncTracks($playlist, [
        FakeProviderAdapter::aTrack('Rendezvous (Remastered)', 'VID_A'),
        FakeProviderAdapter::aTrack('Nouveau', 'VID_B'),
    ]);

    $kept = $playlist->tracks()->where('youtube_video_id', 'VID_A')->firstOrFail();

    expect($kept->id)->toBe($originalId)
        ->and($kept->title)->toBe('Rendezvous (Remastered)')
        ->and(storedVideoIds($playlist))->toBe(['VID_A', 'VID_B']);
});

it('removes tracks that left the playlist', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [
        FakeProviderAdapter::aTrack('One', 'VID_A'),
        FakeProviderAdapter::aTrack('Two', 'VID_B'),
    ]);

    $changed = syncTracks($playlist, [FakeProviderAdapter::aTrack('Two', 'VID_B')]);

    expect($changed)->toBeTrue()
        ->and(storedVideoIds($playlist))->toBe(['VID_B']);
});

it('reorders tracks in place instead of recreating them', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [
        FakeProviderAdapter::aTrack('One', 'VID_A'),
        FakeProviderAdapter::aTrack('Two', 'VID_B'),
    ]);
    $ids = $playlist->tracks()->pluck('id', 'youtube_video_id')->all();

    syncTracks($playlist, [
        FakeProviderAdapter::aTrack('Two', 'VID_B'),
        FakeProviderAdapter::aTrack('One', 'VID_A'),
    ]);

    expect(storedVideoIds($playlist))->toBe(['VID_B', 'VID_A'])
        ->and($playlist->tracks()->pluck('id', 'youtube_video_id')->all())->toEqual($ids);
});

it('matches repeated copies of the same video one by one', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [
        FakeProviderAdapter::aTrack('Loop', 'VID_A'),
        FakeProviderAdapter::aTrack('Loop', 'VID_A'),
    ]);
    $firstCopyId = $playlist->tracks()->value('id');

    syncTracks($playlist, [FakeProviderAdapter::aTrack('Loop', 'VID_A')]);

    expect($playlist->tracks()->pluck('id')->all())->toBe([$firstCopyId]);
});

it('matches a track without a video id by its title and artists', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [FakeProviderAdapter::aTrack('Private upload', null)]);
    $originalId = $playlist->tracks()->value('id');

    $changed = syncTracks($playlist, [FakeProviderAdapter::aTrack('Private upload', null)]);

    expect($changed)->toBeFalse()
        ->and($playlist->tracks()->pluck('id')->all())->toBe([$originalId]);
});

it('records when the playlist content last changed', function (): void {
    $this->freezeSecond();
    $playlist = Playlist::factory()->create(['last_changed_at' => null]);

    syncTracks($playlist, [FakeProviderAdapter::aTrack('One', 'VID_A')]);

    expect($playlist->refresh()->last_changed_at)->toEqual(now());
});

it('reports no change and keeps the change date when nothing moved', function (): void {
    $playlist = Playlist::factory()->create();
    syncTracks($playlist, [FakeProviderAdapter::aTrack('One', 'VID_A')]);
    $changedAt = $playlist->refresh()->last_changed_at;

    $this->travel(1)->day();
    $changed = syncTracks($playlist, [FakeProviderAdapter::aTrack('One', 'VID_A')]);

    expect($changed)->toBeFalse()
        ->and($playlist->refresh()->last_changed_at)->toEqual($changedAt);
});

function trackLasting(?int $seconds, string $videoId = 'VID_D', ?string $thumbnailUrl = 'https://img.test/t.jpg'): RemoteTrack
{
    return new RemoteTrack(
        ref: new App\Services\Music\Data\ProviderRef(App\Enums\Provider::YouTubeMusic, $videoId),
        title: 'Timed',
        artists: [new App\Services\Music\Data\RemoteArtist(null, 'Someone', App\Enums\ArtistRole::Main)],
        album: null,
        durationSeconds: $seconds,
        isrc: null,
        kind: App\Enums\SourceKind::Audio,
        isExplicit: false,
        isAvailable: true,
        thumbnailUrl: $thumbnailUrl,
    );
}

it('stores track durations as a clock', function (?int $seconds, ?string $clock): void {
    $playlist = Playlist::factory()->create();

    syncTracks($playlist, [trackLasting($seconds)]);

    expect($playlist->tracks()->value('duration'))->toBe($clock);
})->with([
    'minutes' => [199, '3:19'],
    'past an hour' => [3723, '1:02:03'],
    'unknown' => [null, null],
]);

it('serves track thumbnails through the local proxy', function (): void {
    $playlist = Playlist::factory()->create();

    syncTracks($playlist, [trackLasting(199, thumbnailUrl: 'https://img.test/t.jpg')]);

    expect($playlist->tracks()->value('thumbnail_url'))->toBe(App\Services\Thumbnails\ThumbnailProxy::url('https://img.test/t.jpg'));
});

it('stores the playlist length the same whatever the locale of the sync', function (): void {
    app()->setLocale('fr_BE');
    Carbon\Carbon::setLocale('fr_BE');
    $playlist = Playlist::factory()->create();

    syncTracks($playlist, [trackLasting(3600, 'VID_E'), trackLasting(720, 'VID_F')]);

    expect($playlist->refresh()->duration)->toBe('1h 12m');
});
