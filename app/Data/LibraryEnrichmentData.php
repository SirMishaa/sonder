<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\LibraryEnrichmentState;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How far the library's tracks got: matched to a recording (resolved, not
 * found, failed, or still pending), then described by every source.
 */
#[TypeScript]
final class LibraryEnrichmentData extends Data
{
    /**
     * @param  int  $described  resolved recordings every source has answered about
     * @param  int  $withGenre  resolved recordings carrying at least one genre
     * @param  int  $withTags  resolved recordings carrying a tag of their own or of their main artist
     * @param  string  $updatedAt  ISO 8601
     * @param  list<RecentEnrichmentData>  $recent  the tracks described last, newest first
     */
    public function __construct(
        public LibraryEnrichmentState $state,
        public int $total,
        public int $resolved,
        public int $notFound,
        public int $failed,
        public int $pending,
        public int $recordings,
        public int $described,
        public int $withGenre,
        public int $withTags,
        public string $updatedAt,
        public array $recent = [],
    ) {}
}
