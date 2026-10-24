<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use WpOrg\Requests\Hooks;
use WpOrg\Requests\Response;
use WpOrg\Requests\Session;

/**
 * Watches the raw responses of one ytmusicapi call, which only hands back
 * parsed payloads, to tell whether YouTube Music still served the session
 * signed in and which cookies it tried to rotate. Only cookie names are
 * kept, never their values. One instance per call keeps it Octane-safe.
 */
final class SessionObserver
{
    private int $requests = 0;

    private ?int $status = null;

    private ?bool $loggedIn = null;

    /** @var array<string, true> */
    private array $cookiesSet = [];

    /**
     * Registers the observer on the session's hooks, keeping any hook
     * already there.
     */
    public function attach(Session $session): Session
    {
        $hooks = $session->options['hooks'] ?? new Hooks();
        $hooks->register('requests.after_request', $this->observe(...));
        $session->options['hooks'] = $hooks;

        return $session;
    }

    public function observe(Response $response): void
    {
        $this->requests++;
        $this->status = is_int($response->status_code) ? $response->status_code : null;

        foreach ($response->headers->getValues('set-cookie') ?? [] as $cookie) {
            $name = mb_trim(explode('=', $cookie, 2)[0]);

            if ($name !== '') {
                $this->cookiesSet[$name] = true;
            }
        }

        $this->loggedIn = $this->loggedInFlag($response->body) ?? $this->loggedIn;
    }

    public function requests(): int
    {
        return $this->requests;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * What the last response that said so reported; null when none did.
     */
    public function loggedIn(): ?bool
    {
        return $this->loggedIn;
    }

    /**
     * @return list<string>
     */
    public function cookiesSet(): array
    {
        $names = array_keys($this->cookiesSet);
        sort($names);

        return $names;
    }

    /**
     * YouTube Music reports the session state in the `logged_in` tracking
     * param of `responseContext.serviceTrackingParams`.
     */
    private function loggedInFlag(string $body): ?bool
    {
        $payload = json_decode($body, true);

        if (! is_array($payload) || ! is_array($payload['responseContext']['serviceTrackingParams'] ?? null)) {
            return null;
        }

        foreach ($payload['responseContext']['serviceTrackingParams'] as $service) {
            foreach ((array) ($service['params'] ?? []) as $param) {
                if (is_array($param) && ($param['key'] ?? null) === 'logged_in') {
                    return ($param['value'] ?? null) === '1';
                }
            }
        }

        return null;
    }
}
