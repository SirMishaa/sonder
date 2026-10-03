<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DownloadThumbnailAction;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The `{hash}` segment may end with the image's extension (see
 * ThumbnailProxy); URLs stored before it did still resolve.
 */
final readonly class ThumbnailController
{
    public function show(string $hash, DownloadThumbnailAction $download): StreamedResponse
    {
        $hash = Str::before($hash, '.');
        $url = request()->query('url');

        if (empty($url) || ! is_string($url) || hash('md5', $url) !== $hash) {
            abort(404);
        }

        $filename = $download->handle($url);

        if ($filename === null) {
            abort(404);
        }

        return Storage::disk()->response($filename, headers: [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
