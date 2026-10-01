<?php

declare(strict_types=1);

use App\Models\PlayerQueue;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('hands the saved queue to the browser once', function (): void {
    $user = User::factory()->create();
    PlayerQueue::factory()->for($user)->create(['current_index' => 1, 'version' => 3]);

    $response = $this->actingAs($user)->get(route('user-profile.edit'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('playerQueue.index', 1)
        ->where('playerQueue.version', 3)
        ->where('playerQueue.origin', 'playlist')
        ->has('playerQueue.tracks', 3)
        ->where('playerQueue.tracks.0.queued', false));
});

it('shares no queue before anything was saved', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('user-profile.edit'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->where('playerQueue', null));
});

it('shares no queue with guests', function (): void {
    $this->get(route('login'))->assertInertia(fn (AssertableInertia $page) => $page->where('playerQueue', null));
});
