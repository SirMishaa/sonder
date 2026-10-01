<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use WpOrg\Requests\Session;

/**
 * An HTTP session for ytmusicapi that keeps no cookie jar.
 *
 * ytmusicapi's default session stores the cookies YouTube Music sets on its
 * anonymous visitor-id request, then sends them on every later call as a
 * second `Cookie` header next to the user's. YouTube Music now reads that
 * second header and serves the call as signed out, so every cookie looks
 * expired. The user's cookie, passed as a header, is the only one to send.
 */
final class CookielessSession
{
    public static function create(): Session
    {
        $session = new Session();
        $session->options['timeout'] = 30;
        $session->options['cookies'] = false;

        return $session;
    }
}
