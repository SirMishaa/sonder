<?php

declare(strict_types=1);

use App\Models\Listen;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function listenPayload(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'youtube_video_id' => 'UcOUJM08bYk',
        'title' => 'Survival',
        'artists' => 'Muse',
        'youtube_playlist_id' => 'PL1',
        'origin' => 'playlist',
        'end_reason' => 'skipped',
        'started_at' => now()->subSeconds(30)->toIso8601String(),
        'ended_at' => now()->toIso8601String(),
        'position_seconds' => 28,
        'listened_seconds' => 28,
        'duration_seconds' => 258,
        ...$overrides,
    ];
}

it('records a listen for the signed-in user', function (): void {
    $user = User::factory()->create();
    $payload = listenPayload();

    $response = $this->actingAs($user)->postJson(route('listen.store'), $payload);

    $response->assertNoContent();
    $listen = Listen::query()->findOrFail($payload['id']);
    expect($listen->user_id)->toBe($user->id)
        ->and($listen->youtube_video_id)->toBe('UcOUJM08bYk')
        ->and($listen->origin->value)->toBe('playlist')
        ->and($listen->end_reason->value)->toBe('skipped')
        ->and($listen->listened_seconds)->toBe(28)
        ->and($listen->duration_seconds)->toBe(258);
});

it('ignores the same listen sent twice', function (): void {
    $user = User::factory()->create();
    $payload = listenPayload();

    $this->actingAs($user)->postJson(route('listen.store'), $payload)->assertNoContent();
    $this->actingAs($user)->postJson(route('listen.store'), $payload)->assertNoContent();

    expect(Listen::query()->count())->toBe(1);
});

it('never takes the owner from the request body', function (): void {
    $user = User::factory()->create();
    $someoneElse = User::factory()->create();
    $payload = listenPayload(['user_id' => $someoneElse->id]);

    $this->actingAs($user)->postJson(route('listen.store'), $payload)->assertNoContent();

    expect(Listen::query()->findOrFail($payload['id'])->user_id)->toBe($user->id);
});

it('redirects guests to the login page', function (): void {
    $this->post(route('listen.store'), listenPayload())->assertRedirect(route('login'));

    expect(Listen::query()->count())->toBe(0);
});

it('rejects invalid listens', function (array $overrides, string $field): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('listen.store'), listenPayload($overrides));

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Listen::query()->count())->toBe(0);
})->with([
    'missing id' => [['id' => null], 'id'],
    'id is not a uuid' => [['id' => 'nope'], 'id'],
    'video id with spaces' => [['youtube_video_id' => 'not a video'], 'youtube_video_id'],
    'video id too long' => [['youtube_video_id' => str_repeat('a', 12)], 'youtube_video_id'],
    'unknown origin' => [['origin' => 'radio'], 'origin'],
    'unknown end reason' => [['end_reason' => 'bored'], 'end_reason'],
    'ends before it starts' => [['started_at' => now()->toIso8601String(), 'ended_at' => now()->subMinute()->toIso8601String()], 'ended_at'],
    'ends in the future' => [['ended_at' => now()->addMinutes(5)->toIso8601String()], 'ended_at'],
    'negative position' => [['position_seconds' => -1], 'position_seconds'],
    'listened longer than the listen lasted' => [['listened_seconds' => 120], 'listened_seconds'],
]);
