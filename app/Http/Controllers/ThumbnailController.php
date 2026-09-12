<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DownloadThumbnailAction;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final readonly class ThumbnailController
{
    public function show(string $hash, DownloadThumbnailAction $download): Response
    {
        $url = request()->query('url');

        if (empty($url) || hash('md5', $url) !== $hash) {
            abort(404);
        }

        $filename = $download->handle($url);

        if ($filename === null || ! Storage::disk('public')->exists($filename)) {
            abort(404);
        }

        $file = Storage::disk('public')->get($filename);
        $mimeType = Storage::disk('public')->mimeType($filename) ?: 'image/jpeg';

        return response($file, 200)->header('Content-Type', $mimeType);
    }
}
