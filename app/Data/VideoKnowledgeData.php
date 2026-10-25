<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What Sonder knows about one YouTube video, for the extension's drawer.
 */
#[TypeScript]
final class VideoKnowledgeData extends Data
{
    /**
     * @param  list<string>  $playlists  titles, alphabetical
     */
    public function __construct(
        public bool $inLibrary,
        public array $playlists,
        public int $listenCount,
        public ?string $lastListenedAt,
    ) {}
}
