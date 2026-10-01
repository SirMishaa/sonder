<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic\Gateway;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderUnavailable;
use App\Services\Music\YouTubeMusic\CookielessSession;
use App\Services\Music\YouTubeMusic\YouTubeMusicErrorTranslator;
use Throwable;
use Ytmusicapi\YTMusic;

/**
 * The only class that talks to YouTube Music, through the ytmusicapi
 * package. It cannot be covered by tests; everything around it can.
 */
final readonly class YtmusicapiGateway implements YouTubeMusicGateway
{
    public function __construct(private YouTubeMusicErrorTranslator $errors) {}

    public function account(string $cookie): object
    {
        return $this->object($this->call($cookie, fn (YTMusic $music): mixed => $music->get_account()));
    }

    public function library(string $cookie): array
    {
        $payload = $this->call($cookie, fn (YTMusic $music): mixed => $music->get_library_playlists(limit: 200));

        if (! is_array($payload)) {
            throw new ProviderUnavailable(Provider::YouTubeMusic, 'the library listing is not a list');
        }

        return array_values(array_filter($payload, is_object(...)));
    }

    public function playlist(string $cookie, string $playlistId): object
    {
        return $this->object($this->call($cookie, fn (YTMusic $music): mixed => $music->get_playlist($playlistId, limit: 500)));
    }

    /**
     * The package declares no return types; an unexpected shape means the
     * private API changed, which is an outage from Sonder's point of view.
     */
    private function object(mixed $payload): object
    {
        return is_object($payload)
            ? $payload
            : throw new ProviderUnavailable(Provider::YouTubeMusic, 'expected an object payload, got '.get_debug_type($payload));
    }

    /**
     * @param  callable(YTMusic): mixed  $callback
     */
    private function call(string $cookie, callable $callback): mixed
    {
        try {
            return $callback(new YTMusic($cookie, requests_session: CookielessSession::create()));
        } catch (Throwable $failure) {
            throw $this->errors->translate($failure);
        }
    }
}
