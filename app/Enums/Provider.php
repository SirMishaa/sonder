<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A music service Sonder can connect to. Carries identity only: the adapter
 * and credentials classes are associated in the ProviderRegistry, so this
 * enum never depends on a concrete integration.
 */
enum Provider: string
{
    case YouTubeMusic = 'youtube_music';

    /**
     * Brand name shown to the user; deliberately not translated.
     */
    public function label(): string
    {
        return match ($this) {
            self::YouTubeMusic => 'YouTube Music',
        };
    }
}
