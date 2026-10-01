<?php

declare(strict_types=1);

namespace App\Services\Music\Contracts;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Data\RemoteAccount;

/**
 * The minimum every provider integration offers. Further abilities are
 * separate capability interfaces (ReadsPlaylists, …) an adapter implements
 * when the provider supports them.
 *
 * Implementations must stay stateless: they take credentials per call, so a
 * long-lived Octane worker never carries one account's secrets into another
 * request.
 */
interface ProviderAdapter
{
    public function provider(): Provider;

    /**
     * Verifies the credentials with a live call and describes the account.
     *
     * @throws ProviderException
     */
    public function account(ProviderCredentials $credentials): RemoteAccount;
}
