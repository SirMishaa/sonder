<?php

declare(strict_types=1);

namespace App\Services\YouTubeMusic;

use App\Data\AccountData;
use App\Data\PlaylistData;
use Illuminate\Contracts\Cache\Repository;

/**
 * Caches the YouTube Music account lookup.
 *
 * Playlists are deliberately not cached: the library sync decides what to
 * re-download by comparing fingerprints, and the manual refresh exists to
 * bypass exactly that. A cache in front of either would hand them stale data
 * and make them report a playlist as up to date when it is not.
 */
final readonly class CachedClient implements Client
{
    private const int ACCOUNT_TTL = 86400; // 24 hours

    public function __construct(
        private Client $client,
        private Repository $cache,
    ) {}

    public function account(string $cookie): AccountData
    {
        return $this->cache->remember(
            $this->key($cookie, 'account'),
            self::ACCOUNT_TTL,
            fn (): AccountData => $this->client->account($cookie),
        );
    }

    public function playlists(string $cookie): array
    {
        return $this->client->playlists($cookie);
    }

    public function playlist(string $cookie, string $playlistId, ?int $trackCount = null): PlaylistData
    {
        return $this->client->playlist($cookie, $playlistId, $trackCount);
    }

    /**
     * Scopes cache entries to an account without ever putting the cookie, or
     * anything derived from it that could be reversed, into a cache key.
     */
    private function key(string $cookie, string $suffix): string
    {
        return hash('sha256', $cookie)
                |> (fn ($x) => mb_substr($x, 0, 16))
                |> (fn ($x) => sprintf('ytm:%s:%s', $x, $suffix));
    }
}
