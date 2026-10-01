<?php

declare(strict_types=1);

namespace App\Services\Music\Contracts;

use App\Enums\Provider;

/**
 * Whatever a provider needs to act for an account (a cookie, OAuth tokens…).
 * Stored encrypted as the array form.
 */
interface ProviderCredentials
{
    /**
     * @param  array<string, string>  $values
     */
    public static function fromArray(array $values): static;

    public function provider(): Provider;

    /**
     * @return array<string, string>
     */
    public function toArray(): array;
}
