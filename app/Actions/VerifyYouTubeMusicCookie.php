<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Asks YouTube Music whether it still accepts an account's cookie, and
 * records the answer on the account.
 *
 * It reads the library listing rather than the account: that is the call a
 * sync depends on, and unlike the account lookup it is never cached, so a
 * success here means the cookie works right now.
 */

use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderException;
use App\Models\YouTubeMusicAccount;
use App\Services\Music\Contracts\ReadsPlaylists;
use App\Services\Music\ProviderRegistry;

final readonly class VerifyYouTubeMusicCookie
{
    public function __construct(private ProviderRegistry $providers) {}

    /**
     * Checks the stored cookie with a live library listing. Only a refused
     * session, an empty library included, flags it expired; an outage propagates so the caller can tell
     * "expired" from "could not check".
     *
     * @return bool Whether the cookie still works.
     *
     * @throws ProviderException When the provider cannot be reached.
     */
    public function handle(YouTubeMusicAccount $account): bool
    {
        try {
            $this->providers->require($account->provider(), ReadsPlaylists::class)->playlists($account->credentials());
        } catch (CredentialsRejected $exception) {
            report($exception);
            $account->markCookieExpired();

            return false;
        }

        $account->markCookieWorking();

        return true;
    }
}
