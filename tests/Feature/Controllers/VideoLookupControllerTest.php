<?php

declare(strict_types=1);

use App\Models\ExtensionCommand;
use App\Models\Listen;
use App\Models\Playlist;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * @param  list<string>  $videoIds
 */
function aLibraryPlaylist(User $user, string $title, array $videoIds, bool $removed = false): Playlist
{
    $account = YouTubeMusicAccount::query()->firstWhere('user_id', $user->id)
        ?? YouTubeMusicAccount::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($account, 'youtubeMusicAccount')->create([
        'title' => $title,
        'removed_at' => $removed ? now() : null,
    ]);

    foreach ($videoIds as $position => $videoId) {
        $playlist->tracks()->create([
            'youtube_video_id' => $videoId,
            'title' => "Track {$videoId}",
            'artists' => 'Artist',
            'position' => $position,
        ]);
    }

    return $playlist;
}

it('describes videos in the library with their playlists and listens', function (): void {
    $user = User::factory()->create();
    aLibraryPlaylist($user, 'Rock FR', ['sunburn0001']);
    aLibraryPlaylist($user, 'Gaming', ['sunburn0001', 'otherTrack1']);
    aLibraryPlaylist($user, 'Old', ['sunburn0001'], removed: true);
    Listen::factory()->for($user)->count(2)->create(['youtube_video_id' => 'sunburn0001', 'started_at' => now()->subDays(5)]);
    $last = Listen::factory()->for($user)->create(['youtube_video_id' => 'sunburn0001', 'started_at' => now()->subDays(2)]);
    Sanctum::actingAs($user, ['extension']);

    $this->postJson(route('video-lookup.store'), ['videoIds' => ['sunburn0001'], 'listId' => null])
        ->assertOk()
        ->assertExactJson([
            'videos' => [
                'sunburn0001' => [
                    'inLibrary' => true,
                    'playlists' => ['Gaming', 'Rock FR'],
                    'listenCount' => 3,
                    'lastListenedAt' => $last->started_at->toIso8601String(),
                ],
            ],
            'list' => null,
        ]);
});

it('describes unknown videos as not in the library', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['extension']);

    $this->postJson(route('video-lookup.store'), ['videoIds' => ['unknown0001']])
        ->assertOk()
        ->assertJsonPath('videos.unknown0001', [
            'inLibrary' => false,
            'playlists' => [],
            'listenCount' => 0,
            'lastListenedAt' => null,
        ]);
});

it('ignores another user\'s library and listens', function (): void {
    $other = User::factory()->create();
    aLibraryPlaylist($other, 'Theirs', ['sunburn0001']);
    Listen::factory()->for($other)->create(['youtube_video_id' => 'sunburn0001']);
    ExtensionCommand::factory()->for($other)->mix('RDmix')->create();
    Sanctum::actingAs(User::factory()->create(), ['extension']);

    $this->postJson(route('video-lookup.store'), ['videoIds' => ['sunburn0001'], 'listId' => 'RDmix'])
        ->assertOk()
        ->assertJsonPath('videos.sunburn0001.inLibrary', false)
        ->assertJsonPath('videos.sunburn0001.listenCount', 0)
        ->assertJsonPath('list.sentBefore', false);
});

it('says whether a mix was sent before', function (): void {
    $user = User::factory()->create();
    $sent = ExtensionCommand::factory()->for($user)->mix('RDmix')->create(['created_at' => now()->subHour()]);
    Sanctum::actingAs($user, ['extension']);

    $this->postJson(route('video-lookup.store'), ['videoIds' => ['sunburn0001'], 'listId' => 'RDmix'])
        ->assertOk()
        ->assertJsonPath('list', [
            'sentBefore' => true,
            'lastSentAt' => $sent->created_at->toIso8601String(),
        ]);
});

it('rejects invalid video ids', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['extension']);

    $this->postJson(route('video-lookup.store'), ['videoIds' => ['nope']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('videoIds.0');
});

it('runs a bounded number of queries', function (): void {
    $user = User::factory()->create();
    aLibraryPlaylist($user, 'Big', array_map(fn (int $i): string => sprintf('video%06d', $i), range(1, 50)));
    Sanctum::actingAs($user, ['extension']);
    $videoIds = array_map(fn (int $i): string => sprintf('video%06d', $i), range(1, 50));

    DB::enableQueryLog();
    $this->postJson(route('video-lookup.store'), ['videoIds' => $videoIds, 'listId' => 'RDmix'])->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(3);
});
