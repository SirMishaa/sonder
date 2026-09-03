<?php

declare(strict_types=1);

use App\Exceptions\YouTubeMusicException;
use App\Services\YouTubeMusic\CachedClient;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeYouTubeMusicClient;

function cached(FakeYouTubeMusicClient $inner): CachedClient
{
    return new CachedClient($inner, Cache::store('array'));
}

it('reads playlists once and serves the rest from cache', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $inner->playlists = [FakeYouTubeMusicClient::aPlaylistSummary()];

    $client = cached($inner);

    $client->playlists('cookie-a');
    $client->playlists('cookie-a');

    expect($inner->callCount('playlists'))->toBe(1);
});

it('keeps accounts apart so one cookie never sees another one cache', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $client = cached($inner);

    $client->playlists('cookie-a');
    $client->playlists('cookie-b');

    expect($inner->callCount('playlists'))->toBe(2);
});

it('reuses a cached playlist while its track count is unchanged', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $inner->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist();
    $client = cached($inner);

    $client->playlist('cookie', 'PL1', 12);
    $client->playlist('cookie', 'PL1', 12);

    expect($inner->callCount('playlist'))->toBe(1);
});

it('fetches again once the track count moves', function (): void {
    // The count is part of the cache key, so a playlist that gained or lost a
    // track lands on a different key. No explicit invalidation to forget.
    $inner = new FakeYouTubeMusicClient();
    $inner->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist();
    $client = cached($inner);

    $client->playlist('cookie', 'PL1', 12);
    $client->playlist('cookie', 'PL1', 13);

    expect($inner->callCount('playlist'))->toBe(2);
});

it('keeps distinct playlists on distinct keys', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $inner->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');
    $inner->tracks['PL2'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL2');
    $client = cached($inner);

    $client->playlist('cookie', 'PL1', 12);
    $client->playlist('cookie', 'PL2', 12);

    expect($inner->callCount('playlist'))->toBe(2);
});

it('still caches a playlist whose count is unknown', function (): void {
    // It cannot detect change, so it gets a short TTL rather than no cache:
    // loading a large playlist costs a chain of continuation requests.
    $inner = new FakeYouTubeMusicClient();
    $inner->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist();
    $client = cached($inner);

    $client->playlist('cookie', 'PL1');
    $client->playlist('cookie', 'PL1');

    expect($inner->callCount('playlist'))->toBe(1);
});

it('caches the account lookup', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $client = cached($inner);

    $client->account('cookie');
    $client->account('cookie');

    expect($inner->callCount('account'))->toBe(1);
});

it('never puts the cookie in a cache key', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $cache = Cache::store('array');
    $client = new CachedClient($inner, $cache);
    $cookie = 'SAPISID=super-secret-value';

    $client->playlists($cookie);

    $keys = array_keys($cache->getStore()->all());

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect($key)->not->toContain('super-secret-value')
            ->and($key)->not->toContain('SAPISID');
    }
});

it('lets failures through instead of caching them', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $inner->shouldFail = true;

    $client = cached($inner);

    expect(fn (): array => $client->playlists('cookie'))
        ->toThrow(YouTubeMusicException::class);

    $inner->shouldFail = false;
    $inner->playlists = [FakeYouTubeMusicClient::aPlaylistSummary()];

    expect($client->playlists('cookie'))->toHaveCount(1);
});
