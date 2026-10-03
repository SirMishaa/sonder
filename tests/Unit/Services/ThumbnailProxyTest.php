<?php

declare(strict_types=1);

use App\Services\Thumbnails\ThumbnailProxy;

it('points a raw image URL at the local thumbnail proxy', function (): void {
    expect(ThumbnailProxy::url('https://img.test/a.jpg'))
        ->toBe(route('thumbnail.show', ['hash' => hash('md5', 'https://img.test/a.jpg').'.jpg', 'url' => 'https://img.test/a.jpg']));
});

it('names the proxied image like a file, so the edge network caches it', function (string $imageUrl, string $extension): void {
    $path = (string) parse_url((string) ThumbnailProxy::url($imageUrl), PHP_URL_PATH);

    expect($path)->toEndWith(".{$extension}");
})->with([
    'its own extension' => ['https://i.ytimg.com/vi/abc/hqdefault.webp', 'webp'],
    'an uppercase extension' => ['https://i.ytimg.com/vi/abc/HQ.PNG', 'png'],
    'no extension at all' => ['https://yt3.googleusercontent.com/abc=w120-h120-l90-rj', 'jpg'],
]);

it('leaves a missing image missing', function (): void {
    expect(ThumbnailProxy::url(null))->toBeNull();
});
