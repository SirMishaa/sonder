<?php

declare(strict_types=1);

use App\Actions\DownloadThumbnailAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

it('downloads and stores a thumbnail from a URL', function (): void {
    $imageUrl = 'https://example.com/image.jpg';

    Http::fake([
        $imageUrl => Http::response('fake-image-content', 200),
    ]);

    $action = new DownloadThumbnailAction();
    $path = $action->handle($imageUrl);

    expect($path)->not->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(Storage::disk('public')->get($path))->toBe('fake-image-content');
});

it('returns null for empty URL', function (): void {
    $action = new DownloadThumbnailAction();
    $path = $action->handle('');

    expect($path)->toBeNull();
});

it('returns null when download fails', function (): void {
    $imageUrl = 'https://example.com/image.jpg';

    Http::fake([
        $imageUrl => Http::response('', 404),
    ]);

    $action = new DownloadThumbnailAction();
    $path = $action->handle($imageUrl);

    expect($path)->toBeNull();
});

it('reuses existing thumbnails instead of downloading again', function (): void {
    $imageUrl = 'https://example.com/image.jpg';

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
