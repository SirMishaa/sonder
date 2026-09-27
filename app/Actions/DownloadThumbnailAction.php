<?php

declare(strict_types=1);

namespace App\Actions;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Uri\WhatWg\Url;

/**
 * Mirrors a remote thumbnail onto the application's default disk and returns
 * its path. Which disk that is (local in development, the attached Cloud
 * bucket in production) is decided by configuration, not by this action.
 *
 * Only HTTPS URLs on YouTube's image hosts are fetched, and redirects are not
 * followed, so the thumbnail endpoint cannot be used to make the server
 * request arbitrary addresses. The URL is parsed once with the WHATWG parser
 * and its normalized form is what gets requested, so the host that was
 * checked is the host that is contacted.
 */
final readonly class DownloadThumbnailAction
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_HOSTS = [
        '*.googleusercontent.com',
        '*.ytimg.com',
        '*.gstatic.com',
    ];

    public function handle(string $url): ?string
    {
        $source = $this->allowedSource($url);

        if ($source === null) {
            return null;
        }

        $hash = hash('md5', $url);
        $extension = $this->getExtension($source);
        $filename = "thumbnails/{$hash}.{$extension}";

        if (Storage::disk()->exists($filename)) {
            return $filename;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->withoutRedirecting()
                ->get($source->toAsciiString());

            if (! $response->successful()) {
                return null;
            }

            Storage::disk()->put($filename, $response->body());

            return $filename;
        } catch (Exception) {
            return null;
        }
    }

    private function allowedSource(string $url): ?Url
    {
        $source = Url::parse($url);
        $host = $source?->getAsciiHost();

        if ($source === null || $source->getScheme() !== 'https' || $host === null) {
            return null;
        }

        return Str::is(self::ALLOWED_HOSTS, $host) ? $source : null;
    }

    private function getExtension(Url $source): string
    {
        $extension = pathinfo($source->getPath(), PATHINFO_EXTENSION);

        $validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array(Str::lower($extension), $validExtensions)) {
            return Str::lower($extension);
        }

        return 'jpg';
    }
}
