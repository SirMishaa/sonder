<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A library track whose recording was just described, with what was learned.
 */
#[TypeScript]
final class RecentEnrichmentData extends Data
{
    /**
     * @param  list<string>  $genres  strongest first, at most three
     * @param  list<string>  $tags  the strongest tags that are not genres (moods, scenes), at most two
     * @param  int|null  $listeners  Last.fm listeners, when known
     * @param  string  $enrichedAt  ISO 8601
     */
    public function __construct(
        public string $videoId,
        public string $title,
        public string $artists,
        public ?string $thumbnailUrl,
        public array $genres,
        public array $tags,
        public ?int $listeners,
        public int $creditCount,
        public ?int $year,
        public string $enrichedAt,
    ) {}
}
