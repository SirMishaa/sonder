<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\YouTubeMusicException;
use App\Models\YouTubeMusicAccount;
use App\Services\YouTubeMusic\Client;

/**
 * Asks YouTube Music whether it still accepts an account's cookie, and
 * records the answer on the account.
 *
 * It reads the library listing rather than the account: that is the call a
 * sync depends on, and unlike the account lookup it is never cached, so a
 * success here means the cookie works right now.
 */
final readonly class VerifyYouTubeMusicCookie
{
    public function __construct(private Client $client) {}

    public function handle(YouTubeMusicAccount $account): bool
    {
        try {
            if ($this->client->playlists($account->cookie) === []) {
                throw YouTubeMusicException::signedOut();
            }
        } catch (YouTubeMusicException $exception) {
            report($exception);
            $account->markCookieExpired();

            return false;
        }

        $account->markCookieWorking();

        return true;
    }
}
