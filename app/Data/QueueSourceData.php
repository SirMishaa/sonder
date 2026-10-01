<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the queue was started from: a playlist, or a list like "Fresh finds".
 */
#[TypeScript]
final class QueueSourceData extends Data
{
    public function __construct(
        public ?string $playlistId,
        public string $title,
    ) {}
}
