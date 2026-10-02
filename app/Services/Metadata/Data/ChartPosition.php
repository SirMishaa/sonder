<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * A track's rank in a chart (1 is the top).
 */
final readonly class ChartPosition
{
    public function __construct(
        public int $rank,
        public string $title,
        public string $artist,
        public ?int $listeners,
        public ?int $playcount,
    ) {}
}
