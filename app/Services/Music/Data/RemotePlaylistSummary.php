<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

/**
 * A playlist as the library listing shows it, without its tracks.
 */
final readonly class RemotePlaylistSummary
{
    /**
     * @param  int|null  $trackCount  Null when the provider gave no reliable count.
     * @param  string|null  $thumbnailUrl  Raw provider image URL.
     */
    public function __construct(
        public ProviderRef $ref,
        public string $title,
        public ?string $description,
        public ?int $trackCount,
        public ?string $thumbnailUrl,
        public ?string $author,
    ) {}

    /**
     * Identifies this version of the listing. Providers rarely expose a
     * modification date, so a change in any listed detail is the cheap
     * signal that the tracks may have changed; edits that leave every detail
     * intact are what the manual refresh is for.
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
