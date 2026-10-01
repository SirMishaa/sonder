<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic\Gateway;

use App\Exceptions\Providers\ProviderException;

/**
 * Raw calls to YouTube Music's private API. Returns the untyped payloads of
 * ytmusicapi (mapping is YouTubeMusicMapper's job) and raises only
 * ProviderException. Takes the cookie per call to stay stateless on Octane.
 */
interface YouTubeMusicGateway
{
    /**
     * @throws ProviderException
     */
    public function account(string $cookie): object;

    /**
     * Every playlist of the library, system ones included, unfiltered.
     *
     * @return list<object>
     *
     * @throws ProviderException
     */
    public function library(string $cookie): array;

    /**
     * @throws ProviderException
     */
    public function playlist(string $cookie, string $playlistId): object;
}
