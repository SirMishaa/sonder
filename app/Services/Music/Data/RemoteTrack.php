<?php

declare(strict_types=1);

namespace App\Services\Music\Data;

use App\Enums\SourceKind;

/**
 * One track as a provider lists it. The ref is null for an item the provider
 * still lists but can no longer play (removed or region-locked upload).
 */
final readonly class RemoteTrack
{
    /**
     * @param  list<RemoteArtist>  $artists  Credited artists, in the provider's order.
     * @param  string|null  $thumbnailUrl  Raw provider image URL, never a local proxy URL.
     */
    public function __construct(
        public ?ProviderRef $ref,
        public string $title,
        public array $artists,
        public ?RemoteAlbum $album,
        public ?int $durationSeconds,
        public ?string $isrc,
        public SourceKind $kind,
        public bool $isExplicit,
        public bool $isAvailable,
        public ?string $thumbnailUrl,
    ) {}

    /**
     * The credited artists as one display string ("A, B").
     */
    public function artistNames(): string
    {
        return implode(', ', array_map(fn (RemoteArtist $artist): string => $artist->name, $this->artists));
    }
}
