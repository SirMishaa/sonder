<?php

declare(strict_types=1);

namespace App\Services\Thumbnails;

/**
 * Serves provider images through Sonder's own thumbnail route, so the
 * browser never loads them from the provider directly.
 */
final class ThumbnailProxy
{
    public static function url(?string $originalUrl): ?string
    {
        if ($originalUrl === null) {
            return null;
        }

        return route('thumbnail.show', ['hash' => hash('md5', $originalUrl), 'url' => $originalUrl]);
    }
}
