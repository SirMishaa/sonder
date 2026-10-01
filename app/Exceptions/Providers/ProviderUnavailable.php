<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use RuntimeException;
use Throwable;

/**
 * The provider could not be reached or answered something unreadable.
 * Transient by nature: it never marks credentials expired.
 */
final class ProviderUnavailable extends RuntimeException implements ProviderException
{
    use DescribesProviderFailure;

    public function __construct(Provider $provider, string $reason, ?Throwable $previous = null)
    {
        $this->failingProvider = $provider;

        parent::__construct("{$provider->label()} unavailable: {$reason}", previous: $previous);
    }

    public function errorCode(): ProviderErrorCode
    {
        return ProviderErrorCode::Unavailable;
    }
}
