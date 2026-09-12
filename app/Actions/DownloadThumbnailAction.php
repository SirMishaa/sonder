<?php

declare(strict_types=1);

namespace App\Actions;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class DownloadThumbnailAction
{
    public function handle(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $hash = hash('md5', $url);
        $extension = $this->getExtension($url);
        $filename = "thumbnails/{$hash}.{$extension}";

        if (Storage::disk('public')->exists($filename)) {
            return $filename;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            Storage::disk('public')->put($filename, $response->body());

            return $filename;
        } catch (Exception) {
            return null;
        }
    }

    private function getExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if ($path === null || $path === false) {
            return 'jpg';
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        $validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array(Str::lower($extension), $validExtensions)) {
            return Str::lower($extension);
        }

        return 'jpg';
    }
}
