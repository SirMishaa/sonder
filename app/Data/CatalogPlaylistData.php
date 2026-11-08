<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CatalogPlaylistData extends Data
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $author,
        public ?int $trackCount,
        public ?string $thumbnailUrl,
    ) {}
}
