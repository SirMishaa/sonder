<?php

declare(strict_types=1);

use App\Actions\SampleLibraryTracks;
use App\Models\Playlist;
use App\Models\YouTubeMusicAccount;

/**
 * @param  array<string, mixed>  $attributes
 */
function sampledTrack(Playlist $playlist, string $videoId, array $attributes = []): void
{
    $playlist->tracks()->create([
        'youtube_video_id' => $videoId,
        'title' => "Track {$videoId}",
        'artists' => 'Some Artist',
        'thumbnail_url' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
        ...$attributes,
    ]);
}

it('samples only tracks with artwork from the account own, current playlists', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $kept = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $removed = Playlist::factory()->for($account, 'youtubeMusicAccount')->removed()->create();
    $foreign = Playlist::factory()->create();

    sampledTrack($kept, 'VID_KEPT');
    sampledTrack($kept, 'VID_NO_ART', ['thumbnail_url' => null]);
    sampledTrack($removed, 'VID_REMOVED');
    sampledTrack($foreign, 'VID_FOREIGN');

    $sample = resolve(SampleLibraryTracks::class)->handle($account, 10);

    expect(array_map(fn ($sampled) => $sampled->track->videoId, $sample))->toBe(['VID_KEPT'])
        ->and($sample[0]->playlistTitle)->toBe($kept->title);
});

it('leaves out the given playlist and every video already in it', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $current = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $other = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();

    sampledTrack($current, 'VID_SHARED');
    sampledTrack($other, 'VID_SHARED');
    sampledTrack($other, 'VID_NEW');

    $sample = resolve(SampleLibraryTracks::class)->handle($account, 10, $current);

    expect(array_map(fn ($sampled) => $sampled->track->videoId, $sample))->toBe(['VID_NEW']);
});

it('returns at most the requested number of distinct videos', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $first = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $second = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();

    foreach (['A', 'B', 'C', 'D'] as $id) {
        sampledTrack($first, "VID_{$id}");
        sampledTrack($second, "VID_{$id}");
    }

    $videoIds = array_map(fn ($sampled) => $sampled->track->videoId, resolve(SampleLibraryTracks::class)->handle($account, 3));

    expect($videoIds)->toHaveCount(3)
        ->and(array_unique($videoIds))->toHaveCount(3);
});
