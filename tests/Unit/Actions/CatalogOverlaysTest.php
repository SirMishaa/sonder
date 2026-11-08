<?php

declare(strict_types=1);

use App\Actions\ArtistGenres;
use App\Actions\DescribeArtistInLibrary;
use App\Actions\MatchLibraryTracks;
use App\Enums\ArtistRole;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Models\Contributor;
use App\Models\Listen;
use App\Models\Playlist;
use App\Models\Tag;
use App\Models\Track;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemoteArtist;
use App\Services\Music\Data\RemoteTrack;
use Illuminate\Support\Facades\DB;

/**
 * @param  non-empty-string  $videoId
 */
function overlayRemoteTrack(string $videoId, string $title, string $artist): RemoteTrack
{
    return new RemoteTrack(
        ref: new ProviderRef(Provider::YouTubeMusic, $videoId),
        title: $title,
        artists: [new RemoteArtist(null, $artist, ArtistRole::Main)],
        album: null,
        durationSeconds: null,
        isrc: null,
        kind: SourceKind::Audio,
        isExplicit: false,
        isAvailable: true,
        thumbnailUrl: null,
    );
}

function overlayLibraryOf(User $user): Playlist
{
    return Playlist::factory()->for(YouTubeMusicAccount::factory()->for($user))->create();
}

it('flags catalog tracks the library holds by video id', function (): void {
    $user = User::factory()->create();
    Track::factory()->for(overlayLibraryOf($user))->create(['youtube_video_id' => 'vid00000001', 'title' => 'Du hast', 'artists' => 'Rammstein']);

    $matches = resolve(MatchLibraryTracks::class)->handle($user, [
        overlayRemoteTrack('vid00000001', 'Whatever', 'Nobody'),
        overlayRemoteTrack('vid00000002', 'Other', 'Nobody'),
    ]);

    expect($matches)->toBe([0 => true, 1 => false]);
});

it('falls back to title and artist when the video id differs', function (): void {
    $user = User::factory()->create();
    Track::factory()->for(overlayLibraryOf($user))->create(['youtube_video_id' => 'old00000001', 'title' => 'Benzin', 'artists' => 'Rammstein']);

    $matches = resolve(MatchLibraryTracks::class)->handle($user, [overlayRemoteTrack('new00000001', 'Benzin', 'Rammstein')]);

    expect($matches)->toBe([0 => true]);
});

it('matches a noisy or accented catalog title to the library title', function (): void {
    $user = User::factory()->create();
    $playlist = overlayLibraryOf($user);
    Track::factory()->for($playlist)->create(['youtube_video_id' => 'old00000001', 'title' => 'Benzin', 'artists' => 'Rammstein']);
    Track::factory()->for($playlist)->create(['youtube_video_id' => 'old00000002', 'title' => 'Deja Vu', 'artists' => 'Beyonce']);

    $matches = resolve(MatchLibraryTracks::class)->handle($user, [
        overlayRemoteTrack('new00000001', 'Benzin (Official Video)', 'Rammstein'),
        overlayRemoteTrack('new00000002', 'Déjà Vu', 'Beyoncé'),
    ]);

    expect($matches)->toBe([0 => true, 1 => true]);
});

it('does not match the same title by another artist', function (): void {
    $user = User::factory()->create();
    $playlist = overlayLibraryOf($user);
    Track::factory()->for($playlist)->create(['youtube_video_id' => 'old00000001', 'title' => 'Benzin', 'artists' => 'Rammstein']);
    Track::factory()->for($playlist)->create(['youtube_video_id' => 'old00000002', 'title' => 'Intro', 'artists' => 'Rammstein Live Recordings']);

    $matches = resolve(MatchLibraryTracks::class)->handle($user, [
        overlayRemoteTrack('new00000001', 'Benzin', 'Someone Else'),
        overlayRemoteTrack('new00000002', 'Intro', 'Rammstein'),
    ]);

    expect($matches)->toBe([0 => false, 1 => false]);
});

it('keys a multi-artist library track on its first artist', function (): void {
    $user = User::factory()->create();
    Track::factory()->for(overlayLibraryOf($user))->create(['youtube_video_id' => 'old00000001', 'title' => 'Gimme', 'artists' => 'Panda Eyes, Apashe']);

    $matches = resolve(MatchLibraryTracks::class)->handle($user, [
        overlayRemoteTrack('new00000001', 'Gimme', 'Panda Eyes'),
        overlayRemoteTrack('new00000002', 'Gimme', 'Apashe'),
    ]);

    expect($matches)->toBe([0 => true, 1 => false]);
});

it('returns nothing for an empty list', function (): void {
    expect(resolve(MatchLibraryTracks::class)->handle(User::factory()->create(), []))->toBe([]);
});

it('never flags another user\'s library', function (): void {
    $other = User::factory()->create();
    Track::factory()->for(overlayLibraryOf($other))->create(['youtube_video_id' => 'vid00000001', 'title' => 'Benzin', 'artists' => 'Rammstein']);

    $matches = resolve(MatchLibraryTracks::class)->handle(User::factory()->create(), [overlayRemoteTrack('vid00000001', 'Benzin', 'Rammstein')]);

    expect($matches)->toBe([0 => false]);
});

it('counts an artist\'s tracks, albums and listens', function (): void {
    $user = User::factory()->create();
    $playlist = overlayLibraryOf($user);
    Track::factory()->for($playlist)->create(['artists' => 'Rammstein', 'album' => 'Mutter']);
    Track::factory()->for($playlist)->create(['artists' => 'rammstein', 'album' => 'Mutter']);
    Track::factory()->for($playlist)->create(['artists' => 'Rammstein, Apashe', 'album' => 'Reise']);
    Track::factory()->for($playlist)->create(['artists' => 'Apashe, Rammstein', 'album' => 'Elsewhere']);

    foreach ([['Du hast', 150], ['Du hast', 150], ['Du hast', 150], ['Benzin', 150]] as $index => [$title, $seconds]) {
        Listen::factory()->for($user)->create([
            'title' => $title,
            'artists' => 'Rammstein',
            'listened_seconds' => $seconds,
            'started_at' => now()->subDays(4 - $index),
        ]);
    }

    $data = resolve(DescribeArtistInLibrary::class)->handle($user, 'Rammstein');

    expect($data->trackCount)->toBe(3)
        ->and($data->albumCount)->toBe(2)
        ->and($data->listenCount)->toBe(4)
        ->and($data->listenedMinutes)->toBe(10)
        ->and($data->topTracks)->toBe([['title' => 'Du hast', 'listens' => 3], ['title' => 'Benzin', 'listens' => 1]])
        ->and($data->firstListenedAt)->not->toBeNull()
        ->and($data->lastListenedAt)->not->toBeNull()
        ->and($data->firstListenedAt < $data->lastListenedAt)->toBeTrue();
});

it('does not count "Rammstein Live Recordings" as Rammstein', function (): void {
    $user = User::factory()->create();
    Track::factory()->for(overlayLibraryOf($user))->create(['artists' => 'Rammstein Live Recordings']);
    Listen::factory()->for($user)->create(['artists' => 'Rammstein Live Recordings']);

    $data = resolve(DescribeArtistInLibrary::class)->handle($user, 'Rammstein');

    expect($data->trackCount)->toBe(0)
        ->and($data->listenCount)->toBe(0)
        ->and($data->firstListenedAt)->toBeNull();
});

it('returns an artist\'s strongest genres', function (): void {
    $artist = Contributor::factory()->create(['name' => 'Rammstein']);
    $weights = ['industrial metal' => [90, true], 'neue deutsche harte' => [70, true], 'german' => [95, false], 'metal' => [40, true]];

    foreach ($weights as $name => [$weight, $isGenre]) {
        DB::table('contributor_tags')->insert([
            'contributor_id' => $artist->id,
            'tag_id' => Tag::named($name, $isGenre)->id,
            'source' => 'lastfm',
            'weight' => $weight,
        ]);
    }

    expect(resolve(ArtistGenres::class)->handle('rammstein', 2))->toBe(['industrial metal', 'neue deutsche harte']);
});
