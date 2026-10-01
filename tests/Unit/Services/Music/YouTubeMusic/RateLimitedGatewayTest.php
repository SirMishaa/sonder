<?php

declare(strict_types=1);

use App\Exceptions\Providers\ProviderRateLimited;
use App\Services\Music\YouTubeMusic\Gateway\RateLimitedGateway;
use Illuminate\Cache\RateLimiter;
use Tests\Support\FakeYouTubeMusicGateway;

beforeEach(function (): void {
    $this->inner = new FakeYouTubeMusicGateway();
    $this->gateway = new RateLimitedGateway($this->inner, resolve(RateLimiter::class));
});

it('passes calls through while the minute budget lasts', function (): void {
    foreach (range(1, 30) as $call) {
        $this->gateway->library('cookie');
    }

    expect($this->inner->callCount('library'))->toBe(30);
});

it('refuses the call over the minute budget without reaching YouTube Music', function (): void {
    foreach (range(1, 30) as $call) {
        $this->gateway->library('cookie');
    }

    expect(fn () => $this->gateway->account('cookie'))->toThrow(ProviderRateLimited::class);
    expect($this->inner->callCount('account'))->toBe(0);
});

it('caps the calls of an hour even when each minute stays under budget', function (): void {
    foreach (range(1, 500) as $call) {
        $this->gateway->library('cookie');

        if ($call % 30 === 0) {
            $this->travel(61)->seconds();
        }
    }

    expect(fn () => $this->gateway->library('cookie'))->toThrow(ProviderRateLimited::class);
});

it('gives every account its own budget', function (): void {
    foreach (range(1, 30) as $call) {
        $this->gateway->library('cookie-a');
    }

    $this->gateway->library('cookie-b');

    expect($this->inner->callCount('library'))->toBe(31);
});
