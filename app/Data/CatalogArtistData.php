<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CatalogArtistData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $thumbnailUrl,
        public ?string $subscribers,
    ) {}
}
