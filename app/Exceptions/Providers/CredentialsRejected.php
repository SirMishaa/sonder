<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use RuntimeException;
use Throwable;

/**
 * The provider refused the session: expired cookie, revoked token, signed-out
 * response. The only failure that marks an account's credentials expired.
 */
final class CredentialsRejected extends RuntimeException implements ProviderException
{
    use DescribesProviderFailure;

    public function __construct(Provider $provider, string $reason, ?Throwable $previous = null)
    {
        $this->failingProvider = $provider;

        parent::__construct("{$provider->label()} rejected the credentials: {$reason}", previous: $previous);
    }

    public function errorCode(): ProviderErrorCode
    {
        return ProviderErrorCode::CredentialsRejected;
    }
}
