<?php

declare(strict_types=1);

use App\Events\PlayerCommandIssued;
use App\Models\ExtensionCommand;
use App\Models\PlayerQueue;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aCommandBody(array $overrides = []): array
{
    return [
        'action' => 'enqueue',
        'kind' => 'mix',
        'listId' => 'RDCLAK5uy_kirby',
        'title' => 'Mix - Foxsky - Kirby Smash',
        'items' => [
            ['videoId' => 'dQw4w9WgXcQ', 'title' => 'Kirby Smash', 'artists' => 'Foxsky', 'durationSeconds' => 201, 'thumbnailUrl' => null],
            ['videoId' => 'Su6YettpXzU', 'title' => 'Ignite', 'artists' => 'Teminite', 'durationSeconds' => 188, 'thumbnailUrl' => 'https://i.ytimg.com/vi/Su6YettpXzU/hqdefault.jpg'],
        ],
        ...$overrides,
    ];
}

it('rejects a request without a token', function (): void {
    $this->postJson(route('player-command.store'), aCommandBody())->assertUnauthorized();
});

it('rejects a token without the extension ability', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['something-else']);

    $this->postJson(route('player-command.store'), aCommandBody())->assertForbidden();
});

it('rejects items without a valid video id', function (array $items, string $error): void {
    Sanctum::actingAs(User::factory()->create(), ['extension']);

    $this->postJson(route('player-command.store'), aCommandBody(['items' => $items]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
})->with([
    'no items' => [[], 'items'],
    'short id' => [[['videoId' => 'abc', 'title' => 'x', 'artists' => 'y', 'durationSeconds' => 1, 'thumbnailUrl' => null]], 'items.0.videoId'],
]);

it('rejects an unknown action', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['extension']);

    $this->postJson(route('player-command.store'), aCommandBody(['action' => 'shuffle']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('action');
});

it('logs, applies and broadcasts a command', function (): void {
    Event::fake([PlayerCommandIssued::class]);
    $user = User::factory()->create();
    PlayerQueue::factory()->for($user)->create(['version' => 5]);
    Sanctum::actingAs($user, ['extension']);
    $traceparent = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

    $response = $this->postJson(route('player-command.store'), aCommandBody(), ['traceparent' => $traceparent]);

    $command = ExtensionCommand::query()->sole();
    $response->assertAccepted()->assertJson(['id' => $command->id]);
    expect($command->user_id)->toBe($user->id)
        ->and($command->list_id)->toBe('RDCLAK5uy_kirby')
        ->and($command->video_ids)->toBe(['dQw4w9WgXcQ', 'Su6YettpXzU'])
        ->and(PlayerQueue::query()->where('user_id', $user->id)->sole()->version)->toBe(6);

    Event::assertDispatched(PlayerCommandIssued::class, fn (PlayerCommandIssued $event): bool => $event->command->is($command)
        && $event->traceparent === $traceparent
        && $event->broadcastOn()[0]->name === 'private-player.'.$user->id
        && count($event->broadcastWith()['items']) === 2);
});
