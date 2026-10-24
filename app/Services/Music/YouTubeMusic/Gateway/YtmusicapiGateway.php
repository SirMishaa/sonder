<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic\Gateway;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderUnavailable;
use App\Services\Music\YouTubeMusic\CookielessSession;
use App\Services\Music\YouTubeMusic\SessionObserver;
use App\Services\Music\YouTubeMusic\YouTubeMusicErrorTranslator;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\SpanKind;
use SensitiveParameter;
use Throwable;
use Ytmusicapi\YTMusic;

/**
 * The only class that talks to YouTube Music, through the ytmusicapi
 * package. It cannot be covered by tests; everything around it can.
 */
final readonly class YtmusicapiGateway implements YouTubeMusicGateway
{
    public function __construct(private YouTubeMusicErrorTranslator $errors) {}

    public function account(#[SensitiveParameter] string $cookie): object
    {
        return $this->object($this->call('account', $cookie, fn (YTMusic $music): mixed => $music->get_account()));
    }

    public function library(#[SensitiveParameter] string $cookie): array
    {
        $payload = $this->call('library', $cookie, fn (YTMusic $music): mixed => $music->get_library_playlists(limit: 200));

        if (! is_array($payload)) {
            throw new ProviderUnavailable(Provider::YouTubeMusic, 'the library listing is not a list');
        }

        return array_values(array_filter($payload, is_object(...)));
    }

    public function playlist(#[SensitiveParameter] string $cookie, string $playlistId): object
    {
        return $this->object($this->call('playlist', $cookie, fn (YTMusic $music): mixed => $music->get_playlist($playlistId, limit: 500)));
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
     * Runs one call in a client span that records whether YouTube Music
     * served the session signed in and which cookies it rotated, so a
     * signed-out cookie shows up in traces the moment it happens. Traces, not
     * metrics: the daily check makes a single call per process, which a
     * cumulative counter would lose.
     *
     * @param  callable(YTMusic): mixed  $callback
     */
    private function call(string $endpoint, #[SensitiveParameter] string $cookie, callable $callback): mixed
    {
        $observer = new SessionObserver();

        return Tracer::newSpan("youtube_music {$endpoint}")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('sonder.youtube_music.endpoint', $endpoint)
            ->measure(function () use ($cookie, $callback, $observer): mixed {
                try {
                    return $callback(new YTMusic($cookie, requests_session: $observer->attach(CookielessSession::create())));
                } catch (Throwable $failure) {
                    throw $this->errors->translate($failure);
                } finally {
                    $this->describeSession($observer);
                }
            });
    }

    private function describeSession(SessionObserver $observer): void
    {
        Tracer::activeSpan()->setAttributes([
            'sonder.youtube_music.requests' => $observer->requests(),
            'sonder.youtube_music.logged_in' => match ($observer->loggedIn()) {
                true => 'true',
                false => 'false',
                null => 'unknown',
            },
            'sonder.youtube_music.cookies_set' => implode(',', $observer->cookiesSet()),
            'http.response.status_code' => $observer->status(),
        ]);
    }
}
