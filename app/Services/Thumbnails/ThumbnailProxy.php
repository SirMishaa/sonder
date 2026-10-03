<?php

declare(strict_types=1);

namespace App\Services\Thumbnails;

use Illuminate\Support\Str;
use Uri\WhatWg\Url;

/**
 * Serves provider images through Sonder's own thumbnail route, so the
 * browser never loads them from the provider directly. The proxied URL ends
 * like an image file because Laravel Cloud's edge network only caches paths
 * with a known file extension.
 */
final class ThumbnailProxy
{
    /**
     * @var list<string>
     */
    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public static function url(?string $originalUrl): ?string
    {
        if ($originalUrl === null) {
            return null;
        }

        $file = hash('md5', $originalUrl).'.'.self::extension($originalUrl);

        return route('thumbnail.show', ['hash' => $file, 'url' => $originalUrl]);
    }

    /**
     * The image's own extension, or `jpg` when its URL carries none (as
     * YouTube's resized images do).
     */
    public static function extension(string $originalUrl): string
    {
        $extension = Str::lower(pathinfo(Url::parse($originalUrl)?->getPath() ?? '', PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS, true) ? $extension : 'jpg';
    }
}
