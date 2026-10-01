<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

use App\Enums\ArtistRole;

/**
 * An artist credited on a remote track. The ref is null when the provider
 * only gives a name.
 */
final readonly class RemoteArtist
{
    public function __construct(
        public ?ProviderRef $ref,
        public string $name,
        public ArtistRole $role,
    ) {}
}
