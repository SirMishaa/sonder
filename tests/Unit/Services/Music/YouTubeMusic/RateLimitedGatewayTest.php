<?php

declare(strict_types=1);

use App\Exceptions\Providers\ProviderRateLimited;
use App\Services\Music\YouTubeMusic\Gateway\RateLimitedGateway;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\RateLimiter as Limiter;
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

it('refuses catalog calls over the catalog budget', function (): void {
    foreach (range(1, 120) as $hit) {
        Limiter::hit('youtube-music-catalog:minute:catalog', 60);
    }

    expect(fn () => $this->gateway->search('daft punk', null, 20))->toThrow(ProviderRateLimited::class);
    expect($this->inner->callCount('search'))->toBe(0);
});

it('keeps the catalog budget apart from account budgets', function (): void {
    foreach (range(1, 30) as $call) {
        $this->gateway->library('cookie');
    }

    $this->inner->artists['UC1'] = (object) ['name' => 'Daft Punk'];

    expect($this->gateway->artist('UC1'))->toBe($this->inner->artists['UC1']);
});
