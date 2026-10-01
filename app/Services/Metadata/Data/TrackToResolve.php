<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

use App\Enums\Provider;

/**
 * A provider's track as the library shows it, to be matched to a recording.
 */
final readonly class TrackToResolve
{
    public function __construct(
        public Provider $provider,
        public string $externalId,
        public string $title,
        public string $artists,
        public ?int $durationSeconds,
    ) {}

    public function key(): string
    {
        return "{$this->provider->value}:{$this->externalId}";
    }
}
