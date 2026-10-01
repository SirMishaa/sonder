<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PlaylistSummaryData extends Data
{
    /**
     * @param  int|null  $trackCount  Null when YouTube Music did not expose a
     *                                parseable count. It is scraped from a
     *                                subtitle string, so it is absent more
     *                                often than you would expect.
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?int $trackCount,
        public ?string $thumbnailUrl,
        public ?string $author,
    ) {}
}
