<?php

declare(strict_types=1);

use App\Actions\DownloadThumbnailAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake();
});

it('downloads and stores a thumbnail from a URL', function (): void {
    $imageUrl = 'https://i.ytimg.com/vi/abc/hqdefault.jpg';

    Http::fake([
        $imageUrl => Http::response('fake-image-content', 200),
    ]);

    $action = new DownloadThumbnailAction();
    $path = $action->handle($imageUrl);

    $expectedPath = 'thumbnails/'.md5($imageUrl).'.jpg';

    expect($path)->toBe($expectedPath)
        ->and(Storage::disk()->get($expectedPath))->toBe('fake-image-content');
});

it('returns null for empty URL', function (): void {
    $action = new DownloadThumbnailAction();
    $path = $action->handle('');

    expect($path)->toBeNull();
});

it('returns null when download fails', function (): void {
    $imageUrl = 'https://i.ytimg.com/vi/abc/hqdefault.jpg';

    Http::fake([
        $imageUrl => Http::response('', 404),
    ]);

    $action = new DownloadThumbnailAction();
    $path = $action->handle($imageUrl);

    expect($path)->toBeNull();
});

it('reuses existing thumbnails instead of downloading again', function (): void {
    $imageUrl = 'https://i.ytimg.com/vi/abc/hqdefault.jpg';

    Http::fake([
        $imageUrl => Http::response('fake-image-content', 200),
    ]);

    $action = new DownloadThumbnailAction();

    // Premier téléchargement
    $path1 = $action->handle($imageUrl);

    // Deuxième appel - ne devrait pas télécharger à nouveau
    Http::assertSentCount(1); // Seulement 1 requête HTTP
    $path2 = $action->handle($imageUrl);

    expect($path1)->toBe($path2);
});

it('refuses to fetch URLs outside the YouTube image hosts', function (string $url): void {
    Http::fake();

    $path = (new DownloadThumbnailAction())->handle($url);

    expect($path)->toBeNull();
    Http::assertNothingSent();
})->with([
    'plain http' => 'http://i.ytimg.com/vi/abc/hqdefault.jpg',
    'unrelated host' => 'https://example.com/image.jpg',
    'lookalike host' => 'https://evilytimg.com/image.jpg',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data',
    'backslash authority confusion' => 'https://evil.com\@i.ytimg.com/image.jpg',
    'hex encoded loopback' => 'https://0x7f.1/image.jpg',
    'not a url' => 'not-a-url',
]);

it('does not follow redirects away from the thumbnail host', function (): void {
    $imageUrl = 'https://i.ytimg.com/vi/abc/hqdefault.jpg';

    Http::fake([
        $imageUrl => Http::response('', 302, ['Location' => 'https://example.com/internal']),
        'https://example.com/*' => Http::response('secret', 200),
    ]);

    $path = (new DownloadThumbnailAction())->handle($imageUrl);

    expect($path)->toBeNull();
    Http::assertSentCount(1);
});
