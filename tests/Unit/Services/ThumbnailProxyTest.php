<?php

declare(strict_types=1);

use App\Services\Thumbnails\ThumbnailProxy;

it('points a raw image URL at the local thumbnail proxy', function (): void {
    expect(ThumbnailProxy::url('https://img.test/a.jpg'))
        ->toBe(route('thumbnail.show', ['hash' => hash('md5', 'https://img.test/a.jpg'), 'url' => 'https://img.test/a.jpg']));
});

it('leaves a missing image missing', function (): void {
    expect(ThumbnailProxy::url(null))->toBeNull();
});
