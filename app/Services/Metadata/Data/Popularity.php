<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * How many people listened, and how many times, at the time of reading.
 */
final readonly class Popularity
{
    public function __construct(
        public int $listeners,
        public ?int $playcount,
    ) {}
}
