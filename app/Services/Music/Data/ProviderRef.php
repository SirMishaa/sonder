<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

use App\Enums\Provider;

/**
 * Points at one object (track, playlist, artist, account…) inside a provider.
 */
final readonly class ProviderRef
{
    /**
     * @param  non-empty-string  $externalId  The provider's own identifier.
     */
    public function __construct(
        public Provider $provider,
        public string $externalId,
    ) {}
}
