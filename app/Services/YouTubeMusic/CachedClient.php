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
    private const int ACCOUNT_TTL = 3600;

    private const int PLAYLISTS_TTL = 300;

    private const int PLAYLIST_TTL = 86400;

    private const int UNVERSIONED_PLAYLIST_TTL = 300;

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
        return $this->cache->remember(
            $this->key($cookie, sprintf('playlist:%s:%s', $playlistId, $trackCount ?? 'unknown')),
            $trackCount === null ? self::UNVERSIONED_PLAYLIST_TTL : self::PLAYLIST_TTL,
            fn (): PlaylistData => $this->client->playlist($cookie, $playlistId, $trackCount),
        );
    }

    /**
     * Scopes cache entries to an account without ever putting the cookie, or
     * anything derived from it that could be reversed, into a cache key.
     */
    private function key(string $cookie, string $suffix): string
    {
        return sprintf('ytm:%s:%s', mb_substr(hash('sha256', $cookie), 0, 16), $suffix);
    }
}
