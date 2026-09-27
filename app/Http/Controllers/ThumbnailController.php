<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DownloadThumbnailAction;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ThumbnailController
{
    public function show(string $hash, DownloadThumbnailAction $download): StreamedResponse
    {
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
