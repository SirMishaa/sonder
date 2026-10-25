<?php

declare(strict_types=1);

use App\Actions\ApplyPlayerCommand;
use App\Data\PlayerCommandData;
use App\Data\PlayerCommandItemData;
use App\Enums\ListenOrigin;
use App\Enums\PlayerCommandAction;
use App\Enums\PlayerCommandKind;
use App\Models\PlayerQueue;
use App\Models\User;

/**
 * @param  list<string>  $videoIds
 */
function aPlayerCommand(PlayerCommandAction $action, PlayerCommandKind $kind, array $videoIds, ?string $listId = null): PlayerCommandData
{
    return new PlayerCommandData(
        action: $action,
        kind: $kind,
        listId: $listId,
        title: $kind === PlayerCommandKind::Mix ? 'Mix - Foxsky' : 'Muse - Sunburn',
        items: array_map(fn (string $videoId): PlayerCommandItemData => new PlayerCommandItemData(
            videoId: $videoId,
            title: "Title {$videoId}",
            artists: 'Artist',
            durationSeconds: 200,
            thumbnailUrl: null,
        ), $videoIds),
    );
}

/**
 * A stored queue of tracks `old00000000`, `old00000001`…, the current one at `$index`.
 *
 * @param  list<bool>  $queued  which of the tracks were queued by hand
 */
function aStoredQueue(User $user, int $count, int $index, array $queued = []): PlayerQueue
{
    $tracks = array_map(fn (int $position): array => [
        'key' => "old{$position}",
        'videoId' => sprintf('old%08d', $position),
        'title' => "Old {$position}",
        'artists' => 'Someone',
        'album' => null,
        'duration' => '3:00',
        'durationSeconds' => 180,
        'thumbnailUrl' => null,
        'playlistId' => 'PL1',
        'queued' => $queued[$position] ?? false,
        'genres' => [],
    ], range(0, $count - 1));

    return PlayerQueue::factory()->for($user)->create([
        'tracks' => $tracks,
        'current_index' => $index,
        'version' => 3,
    ]);
}

/**
 * @return list<string|null>
 */
function queuedVideoIds(User $user): array
{
    return array_column(PlayerQueue::query()->where('user_id', $user->id)->sole()->tracks, 'videoId');
}

it('replaces the queue with a mix played now', function (): void {
    $user = User::factory()->create();
    aStoredQueue($user, 3, 1);

    app(ApplyPlayerCommand::class)->handle($user, aPlayerCommand(PlayerCommandAction::PlayNow, PlayerCommandKind::Mix, ['mixA0000000', 'mixB0000000'], 'RDmix'));

    $queue = PlayerQueue::query()->where('user_id', $user->id)->sole();
    expect(queuedVideoIds($user))->toBe(['mixA0000000', 'mixB0000000'])
        ->and($queue->current_index)->toBe(0)
        ->and($queue->source)->toBe(['playlistId' => 'RDmix', 'title' => 'Mix - Foxsky'])
        ->and($queue->origin)->toBe(ListenOrigin::Playlist);
});

it('plays a video now right after the current track', function (): void {
    $user = User::factory()->create();
    aStoredQueue($user, 3, 1);

    app(ApplyPlayerCommand::class)->handle($user, aPlayerCommand(PlayerCommandAction::PlayNow, PlayerCommandKind::Video, ['vidA0000000']));

    $queue = PlayerQueue::query()->where('user_id', $user->id)->sole();
    expect(queuedVideoIds($user))->toBe(['old00000000', 'old00000001', 'vidA0000000', 'old00000002'])
        ->and($queue->current_index)->toBe(2)
        ->and($queue->origin)->toBe(ListenOrigin::Queue);
});

it('starts an empty queue with a video played now', function (): void {
    $user = User::factory()->create();

    app(ApplyPlayerCommand::class)->handle($user, aPlayerCommand(PlayerCommandAction::PlayNow, PlayerCommandKind::Video, ['vidA0000000']));

    $queue = PlayerQueue::query()->where('user_id', $user->id)->sole();
    expect(queuedVideoIds($user))->toBe(['vidA0000000'])
        ->and($queue->current_index)->toBe(0)
        ->and($queue->source)->toBe(['playlistId' => null, 'title' => 'Muse - Sunburn']);
});

it('plays next right after the current track, before tracks already queued', function (): void {
    $user = User::factory()->create();
    aStoredQueue($user, 4, 0, [false, true, false, false]);

    app(ApplyPlayerCommand::class)->handle($user, aPlayerCommand(PlayerCommandAction::PlayNext, PlayerCommandKind::Video, ['vidA0000000']));

    $queue = PlayerQueue::query()->where('user_id', $user->id)->sole();
    expect(queuedVideoIds($user))->toBe(['old00000000', 'vidA0000000', 'old00000001', 'old00000002', 'old00000003'])
        ->and($queue->current_index)->toBe(0)
        ->and($queue->tracks[1]['queued'] ?? false)->toBeTrue();
});

it('enqueues behind the tracks already queued', function (): void {
    $user = User::factory()->create();
    aStoredQueue($user, 4, 0, [false, true, false, false]);

    app(ApplyPlayerCommand::class)->handle($user, aPlayerCommand(PlayerCommandAction::Enqueue, PlayerCommandKind::Mix, ['mixA0000000', 'mixB0000000'], 'RDmix'));

    expect(queuedVideoIds($user))->toBe(['old00000000', 'old00000001', 'mixA0000000', 'mixB0000000', 'old00000002', 'old00000003']);
});

it('keeps the queue within 300 tracks', function (): void {
    $user = User::factory()->create();
    aStoredQueue($user, 300, 299);

    app(ApplyPlayerCommand::class)->handle($user, aPlayerCommand(PlayerCommandAction::PlayNext, PlayerCommandKind::Mix, ['mixA0000000', 'mixB0000000', 'mixC0000000', 'mixD0000000', 'mixE0000000'], 'RDmix'));

    $queue = PlayerQueue::query()->where('user_id', $user->id)->sole();
    $videoIds = queuedVideoIds($user);
    expect($videoIds)->toHaveCount(300)
        ->and($videoIds[0])->toBe('old00000005')
        ->and($videoIds[$queue->current_index])->toBe('old00000299')
        ->and(array_slice($videoIds, -5))->toBe(['mixA0000000', 'mixB0000000', 'mixC0000000', 'mixD0000000', 'mixE0000000']);
});

it('bumps the version', function (): void {
    $user = User::factory()->create();
    aStoredQueue($user, 3, 1);

    $version = app(ApplyPlayerCommand::class)->handle($user, aPlayerCommand(PlayerCommandAction::Enqueue, PlayerCommandKind::Video, ['vidA0000000']));

    expect($version)->toBe(4)
        ->and(PlayerQueue::query()->where('user_id', $user->id)->sole()->version)->toBe(4);
});

it('gives each track of a command its own key, even for a repeated video', function (): void {
    $user = User::factory()->create();

    app(ApplyPlayerCommand::class)->handle($user, aPlayerCommand(PlayerCommandAction::Enqueue, PlayerCommandKind::Mix, ['same0000000', 'other000000', 'same0000000'], 'PLrepeat'));

    $keys = array_column(PlayerQueue::query()->where('user_id', $user->id)->sole()->tracks, 'key');
    expect(array_unique($keys))->toHaveCount(3);
});

it('keeps the new tracks when the upcoming tracks fill the queue', function (): void {
    $user = User::factory()->create();
    aStoredQueue($user, 300, 0, array_fill(0, 300, true));
    $videoIds = array_map(fn (int $i): string => sprintf('new%08d', $i), range(1, 50));

    app(ApplyPlayerCommand::class)->handle($user, aPlayerCommand(PlayerCommandAction::Enqueue, PlayerCommandKind::Mix, $videoIds, 'RDmix'));

    $queue = PlayerQueue::query()->where('user_id', $user->id)->sole();
    $stored = queuedVideoIds($user);
    expect($stored)->toHaveCount(300)
        ->and($stored[$queue->current_index])->toBe('old00000000')
        ->and(array_values(array_intersect($stored, $videoIds)))->toBe($videoIds);
});
