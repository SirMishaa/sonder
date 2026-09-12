<?php

declare(strict_types=1);

namespace App\Services\YouTubeMusic;

use App\Data\AccountData;
use App\Data\PlaylistData;
use Illuminate\Contracts\Cache\Repository;

/**
 * Caches YouTube Music reads.
 *
 * Loading a large playlist costs a chain of sequential "continuation"
 * requests, so it must not be repeated on every page view. Rather than expire
 * playlists on a timer and hope, the cache key embeds the track count: when
 * the playlist changes size the key changes with it and the old entry is
 * simply never read again. No explicit invalidation to write, and none to
 * forget.
 *
 * The count is unreliable (see YtmusicapiClient::trackCount), so when it is
 * unknown the key cannot detect change and a short TTL is used instead.
 */
final readonly class CachedClient implements Client
{
    private const int ACCOUNT_TTL = 86400; // 24 hours

    private const int PLAYLISTS_TTL = 3600; // 1 hour (instead of 5 minutes)

    private const int PLAYLIST_TTL = 604800; // 7 days

    private const int UNVERSIONED_PLAYLIST_TTL = 3600; // 1 hour (instead of 5 minutes)

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
        return $this->cache->remember(
            $this->key($cookie, 'playlists'),
            self::PLAYLISTS_TTL,
            fn (): array => $this->client->playlists($cookie),
        );
    }

    public function playlist(string $cookie, string $playlistId, ?int $trackCount = null): PlaylistData
    {
        return sprintf('playlist:%s:%s', $playlistId, $trackCount ?? 'unknown')
                |> (fn ($x) => $this->key($cookie, $x))
                |> (fn ($x) => $this->cache->remember($x, $trackCount === null ? self::UNVERSIONED_PLAYLIST_TTL : self::PLAYLIST_TTL, fn (): PlaylistData => $this->client->playlist($cookie, $playlistId, $trackCount)));
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
