<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ArtistTallyData extends Data
{
    public function __construct(
        public string $name,
        public int $trackCount,
    ) {}
}
