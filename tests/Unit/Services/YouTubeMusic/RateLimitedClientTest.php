<?php

declare(strict_types=1);

use App\Exceptions\YouTubeMusicRateLimitedException;
use App\Services\YouTubeMusic\RateLimitedClient;
use Illuminate\Cache\RateLimiter;
use Tests\Support\FakeYouTubeMusicClient;

beforeEach(function (): void {
    $this->inner = new FakeYouTubeMusicClient();
    $this->client = new RateLimitedClient($this->inner, resolve(RateLimiter::class));
});

it('passes calls through while the minute budget lasts', function (): void {
    foreach (range(1, 30) as $call) {
        $this->client->playlists('cookie');
    }

    expect($this->inner->callCount('playlists'))->toBe(30);
});

it('refuses the call over the minute budget without reaching YouTube Music', function (): void {
    foreach (range(1, 30) as $call) {
        $this->client->playlists('cookie');
    }

    expect(fn () => $this->client->account('cookie'))
        ->toThrow(YouTubeMusicRateLimitedException::class);
    expect($this->inner->callCount('account'))->toBe(0);
});

it('caps the calls of an hour even when each minute stays under budget', function (): void {
    foreach (range(1, 500) as $call) {
        $this->client->playlists('cookie');

        if ($call % 30 === 0) {
            $this->travel(61)->seconds();
        }
    }

    expect(fn () => $this->client->playlists('cookie'))
        ->toThrow(YouTubeMusicRateLimitedException::class);
});

it('gives every account its own budget', function (): void {
    foreach (range(1, 30) as $call) {
        $this->client->playlists('cookie-a');
    }

    $this->client->playlists('cookie-b');

    expect($this->inner->callCount('playlists'))->toBe(31);
});
