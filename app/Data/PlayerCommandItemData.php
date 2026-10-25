<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One video of a command, as the extension read it on YouTube.
 */
#[TypeScript]
final class PlayerCommandItemData extends Data
{
    public function __construct(
        public string $videoId,
        public string $title,
        public string $artists,
        public int $durationSeconds,
        public ?string $thumbnailUrl,
    ) {}
}
