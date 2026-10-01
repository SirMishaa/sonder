<?php

declare(strict_types=1);

namespace App\Services\YouTubeMusic;

use App\Data\AccountData;
use App\Data\PlaylistData;
use App\Exceptions\YouTubeMusicException;
use Throwable;
use Ytmusicapi\YTMusic;

/**
 * Reaches YouTube Music through the `ytmusicapi/ytmusicapi` package, which
 * drives the private `youtubei/v1` endpoints.
 *
 * This class does nothing but make the call and hand the result to the
 * PayloadMapper. It is the only part of the application that touches the
 * network, and the only part that cannot be covered by tests.
 */
final readonly class YtmusicapiClient implements Client
{
    public function __construct(private PayloadMapper $mapper = new PayloadMapper()) {}

    public function account(string $cookie): AccountData
    {
        return $this->mapper->account(
            $this->object($cookie, fn (YTMusic $music): mixed => $music->get_account()),
        );
    }

    public function playlists(string $cookie): array
    {
        $payloads = $this->list($cookie, fn (YTMusic $music): mixed => $music->get_library_playlists(limit: 200));

        $playlists = [];

        foreach ($payloads as $payload) {
            if (is_object($payload)) {
                $playlists[] = $this->mapper->playlistSummary($payload);
            }

        }

        return $playlists;
    }

    public function playlist(string $cookie, string $playlistId, ?int $trackCount = null): PlaylistData
    {
        return $this->mapper->playlist(
            $this->object($cookie, fn (YTMusic $music): mixed => $music->get_playlist($playlistId, limit: 500)),
            $playlistId,
            $trackCount,
        );
    }

    /**
     * The package declares no return types, so what comes back is genuinely
     * unknown. Checking the shape here turns a silent cascade of undefined
     * property reads inside the mapper into one clear failure.
     *
     * @param  callable(YTMusic): mixed  $callback
     *
     * @throws YouTubeMusicException
     */
    private function object(string $cookie, callable $callback): object
    {
        $payload = $this->call($cookie, $callback);

        if (! is_object($payload)) {
            throw YouTubeMusicException::unexpectedPayload('object');
        }

        return $payload;
    }

    /**
     * @param  callable(YTMusic): mixed  $callback
     * @return array<array-key, mixed>
     *
     * @throws YouTubeMusicException
     */
    private function list(string $cookie, callable $callback): array
    {
        $payload = $this->call($cookie, $callback);

        if (! is_array($payload)) {
            throw YouTubeMusicException::unexpectedPayload('list');
        }

        return $payload;
    }

    /**
     * @param  callable(YTMusic): mixed  $callback
     *
     * @throws YouTubeMusicException
     */
    private function call(string $cookie, callable $callback): mixed
    {
        try {
            return $callback(new YTMusic($cookie, requests_session: CookielessSession::create()));
        } catch (Throwable $throwable) {
            throw YouTubeMusicException::unreachable($throwable);
        }
    }
}
