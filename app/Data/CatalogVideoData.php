<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CatalogVideoData extends Data
{
    public function __construct(
        public ?string $videoId,
        public string $title,
        public ?string $thumbnailUrl,
        public ?int $views,
    ) {}
}
