<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * The account the credentials belong to, as the provider describes it.
 */
final readonly class RemoteAccount
{
    public function __construct(
        public ProviderRef $ref,
        public string $displayName,
    ) {}
}
