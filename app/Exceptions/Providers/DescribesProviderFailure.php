<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;

/**
 * Shared implementation of ProviderException for the concrete failures.
 *
 * @phpstan-require-implements ProviderException
 */
trait DescribesProviderFailure
{
    private Provider $failingProvider;

    public function provider(): Provider
    {
        return $this->failingProvider;
    }

    /**
     * @return array<string, int|string>
     */
    public function context(): array
    {
        return [];
    }

    public function userMessage(): string
    {
        return $this->errorCode()->userMessage($this->failingProvider, $this->context());
    }
}
