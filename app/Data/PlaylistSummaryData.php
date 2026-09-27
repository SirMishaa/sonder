<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PlaylistSummaryData extends Data
{
    /**
     * @param  int|null  $trackCount  Null when YouTube Music did not expose a
     *                                parseable count. It is scraped from a
     *                                subtitle string, so it is absent more
     *                                often than you would expect.
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?int $trackCount,
        public ?string $thumbnailUrl,
        public ?string $author,
    ) {}

    /**
     * Identifies this version of the playlist as the library listing shows
     * it. YouTube Music exposes no modification date, so a change in any of
     * these fields is the only cheap signal that the tracks may have changed.
     * Edits that leave all of them intact (a track swapped deep in the list)
     * are invisible here, which is what the manual refresh is for.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->title,
            $this->description,
            $this->trackCount,
            $this->thumbnailUrl,
            $this->author,
        ], JSON_THROW_ON_ERROR));
    }
}
