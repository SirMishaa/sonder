<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * A tag with its weight (0–100) within one source's answer.
 */
final readonly class WeightedTag
{
    public function __construct(
        public string $name,
        public int $weight,
        public bool $isGenre,
    ) {}
}
