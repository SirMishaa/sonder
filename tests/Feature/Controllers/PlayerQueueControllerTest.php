<?php

declare(strict_types=1);

use App\Models\PlayerQueue;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function queueTrack(array $overrides = []): array
{
    return [
        'key' => 'UcOUJM08bYk#0',
        'videoId' => 'UcOUJM08bYk',
        'title' => 'Survival',
        'artists' => 'Muse',
        'album' => 'The 2nd Law',
        'duration' => '4:18',
        'durationSeconds' => 258,
        'thumbnailUrl' => null,
        'playlistId' => 'PL1',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function queuePayload(array $overrides = []): array
{
    return [
        'tracks' => [
            queueTrack(),
            queueTrack(['key' => 'mNHcvMpcqFM#1@1790866940985', 'videoId' => 'mNHcvMpcqFM', 'title' => 'Hysteria', 'queued' => true]),
        ],
        'index' => 0,
        'source' => ['playlistId' => 'PL1', 'title' => 'Road trip'],
        'origin' => 'playlist',
        ...$overrides,
    ];
}

it('saves the queue of the signed-in user', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson(route('player-queue.update'), queuePayload());

    $response->assertOk()->assertExactJson(['version' => 1]);
    $queue = $user->playerQueue()->firstOrFail();
    expect($queue->current_index)->toBe(0)
        ->and($queue->origin->value)->toBe('playlist')
        ->and($queue->source)->toBe(['playlistId' => 'PL1', 'title' => 'Road trip'])
        ->and(array_column($queue->tracks, 'videoId'))->toBe(['UcOUJM08bYk', 'mNHcvMpcqFM'])
        ->and(array_column($queue->tracks, 'queued'))->toBe([false, true]);
});

it('replaces the saved queue and moves its version on', function (): void {
    $user = User::factory()->create();
    PlayerQueue::factory()->for($user)->create(['version' => 4]);

    $response = $this->actingAs($user)->putJson(route('player-queue.update'), queuePayload(['index' => 1]));

    $response->assertOk()->assertExactJson(['version' => 5]);
    expect(PlayerQueue::query()->count())->toBe(1)
        ->and($user->playerQueue()->firstOrFail()->current_index)->toBe(1);
});

it('saves an emptied queue', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('player-queue.update'), queuePayload(['tracks' => [], 'index' => -1, 'source' => null]))
        ->assertOk();

    expect($user->playerQueue()->firstOrFail()->tracks)->toBe([]);
});

it('keeps an empty artist name as an empty string', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('player-queue.update'), queuePayload(['tracks' => [queueTrack(['artists' => ''])]]))
        ->assertOk();

    expect($user->playerQueue()->firstOrFail()->tracks[0]['artists'])->toBe('');
});

it('fills in the fields an older stored track lacks', function (): void {
    $user = User::factory()->create();
    $legacy = queueTrack();
    unset($legacy['playlistId'], $legacy['album']);

    $this->actingAs($user)
        ->putJson(route('player-queue.update'), queuePayload(['tracks' => [$legacy]]))
        ->assertOk();

    expect($user->playerQueue()->firstOrFail()->tracks[0])
        ->toMatchArray(['playlistId' => null, 'album' => null, 'queued' => false]);
});

it('never touches the queue of another user', function (): void {
    $user = User::factory()->create();
    $theirs = PlayerQueue::factory()->create(['version' => 7]);

    $this->actingAs($user)
        ->putJson(route('player-queue.update'), queuePayload(['user_id' => $theirs->user_id]))
        ->assertOk();

    expect($theirs->fresh()?->version)->toBe(7)
        ->and($user->playerQueue()->exists())->toBeTrue();
});

it('redirects guests to the login page', function (): void {
    $this->put(route('player-queue.update'), queuePayload())->assertRedirect(route('login'));

    expect(PlayerQueue::query()->count())->toBe(0);
});

it('rejects an invalid queue', function (array $overrides, string $field): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson(route('player-queue.update'), queuePayload($overrides));

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(PlayerQueue::query()->count())->toBe(0);
})->with([
    'index past the last track' => [['index' => 2], 'index'],
    'index below nothing loaded' => [['index' => -2], 'index'],
    'unknown origin' => [['origin' => 'radio'], 'origin'],
    'video id with spaces' => [['tracks' => [queueTrack(['videoId' => 'not a video'])]], 'tracks.0.videoId'],
    'repeated keys' => [['tracks' => [queueTrack(), queueTrack()]], 'tracks.0.key'],
    'source without a title' => [['source' => ['playlistId' => 'PL1']], 'source.title'],
    'more tracks than kept' => [['tracks' => array_map(fn (int $position): array => queueTrack(['key' => "k{$position}"]), range(0, 300))], 'tracks'],
]);
