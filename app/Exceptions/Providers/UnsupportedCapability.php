<?php

declare(strict_types=1);

namespace App\Exceptions\Providers;

use App\Enums\Provider;
use LogicException;

/**
 * Code required a capability the provider's adapter does not implement.
 * A programming error, not a provider failure.
 */
final class UnsupportedCapability extends LogicException
{
    public function __construct(Provider $provider, string $capability)
    {
        parent::__construct("{$provider->label()} does not support {$capability}.");
    }
}
