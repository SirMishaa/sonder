<?php

declare(strict_types=1);

use App\Exceptions\YouTubeMusicException;
use App\Services\YouTubeMusic\CachedClient;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeYouTubeMusicClient;

function cached(FakeYouTubeMusicClient $inner): CachedClient
{
    return new CachedClient($inner, Cache::store('array'));
}

it('caches the account lookup', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $client = cached($inner);

    $client->account('cookie');
    $client->account('cookie');

    expect($inner->callCount('account'))->toBe(1);
});

it('keeps accounts apart so one cookie never sees another one cache', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $client = cached($inner);

    $client->account('cookie-a');
    $client->account('cookie-b');

    expect($inner->callCount('account'))->toBe(2);
});

it('always reads playlists from YouTube Music', function (): void {
    // The sync compares fingerprints and the manual refresh bypasses them;
    // either one fed from a cache would call a stale playlist up to date.
    $inner = new FakeYouTubeMusicClient();
    $inner->playlists = [FakeYouTubeMusicClient::aPlaylistSummary()];
    $inner->tracks['PL_TEST'] = FakeYouTubeMusicClient::aPlaylist();
    $client = cached($inner);

    $client->playlists('cookie');
    $client->playlists('cookie');
    $client->playlist('cookie', 'PL_TEST', 12);
    $client->playlist('cookie', 'PL_TEST', 12);

    expect($inner->callCount('playlists'))->toBe(2)
        ->and($inner->callCount('playlist'))->toBe(2);
});

it('never puts the cookie in a cache key', function (): void {
    $inner = new FakeYouTubeMusicClient();
    $store = new ArrayStore();
    $client = new CachedClient($inner, new Repository($store));
    $cookie = 'SAPISID=super-secret-value';

    $client->account($cookie);

    $keys = array_keys($store->all());

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

    expect(fn (): mixed => $client->account('cookie'))
        ->toThrow(YouTubeMusicException::class);

    $inner->shouldFail = false;

    expect($client->account('cookie')->name)->toBe('Test Listener');
});
