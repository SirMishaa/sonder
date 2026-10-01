<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use App\Enums\ProviderErrorCode;
use RuntimeException;

/**
 * Sonder's own call budget for the provider is spent. Expected and frequent
 * under load, so it is excluded from error reporting (bootstrap/app.php).
 */
final class ProviderRateLimited extends RuntimeException implements ProviderException
{
    use DescribesProviderFailure;

    public function __construct(Provider $provider, public readonly int $retryAfter)
    {
        $this->failingProvider = $provider;

        parent::__construct("Too many calls to {$provider->label()}; the next one is allowed in {$retryAfter} seconds.");
    }

    public function errorCode(): ProviderErrorCode
    {
        return ProviderErrorCode::RateLimited;
    }

    /**
     * @return array{seconds: int}
     */
    public function context(): array
    {
        return ['seconds' => $this->retryAfter];
    }
}
