<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CatalogAlbumData extends Data
{
    /**
     * @param  int|null  $trackCount  null until the album's tracks are counted
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $type,
        public ?int $year,
        public string $artists,
        public ?string $artistId,
        public ?string $thumbnailUrl,
        public ?int $trackCount,
    ) {}
}
