<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake();
});

it('serves a thumbnail after downloading it', function (): void {
    $imageUrl = 'https://i.ytimg.com/vi/abc/hqdefault.jpg';
    $hash = md5($imageUrl);

    Http::fake([
        $imageUrl => Http::response('fake-image-content', 200),
    ]);

    $response = $this->get(route('thumbnail.show', ['hash' => $hash, 'url' => $imageUrl]));

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

    expect($response->streamedContent())->toBe('fake-image-content')
        ->and(Storage::disk()->exists('thumbnails/'.$hash.'.jpg'))->toBeTrue();
});

it('returns 404 when hash does not match URL', function (): void {
    $imageUrl = 'https://i.ytimg.com/vi/abc/hqdefault.jpg';
    $wrongHash = 'wrong-hash';

    $response = $this->get(route('thumbnail.show', ['hash' => $wrongHash, 'url' => $imageUrl]));

    $response->assertNotFound();
});

it('returns 404 when URL is missing', function (): void {
    $response = $this->get(route('thumbnail.show', ['hash' => 'some-hash']));

    $response->assertNotFound();
});

it('returns 404 when download fails', function (): void {
    $imageUrl = 'https://i.ytimg.com/vi/abc/hqdefault.jpg';
    $hash = md5($imageUrl);

    Http::fake([
        $imageUrl => Http::response('', 404),
    ]);

    $response = $this->get(route('thumbnail.show', ['hash' => $hash, 'url' => $imageUrl]));

    $response->assertNotFound();
});

it('returns 404 for URLs outside the YouTube image hosts', function (): void {
    $imageUrl = 'https://example.com/image.jpg';

    Http::fake();

    $response = $this->get(route('thumbnail.show', ['hash' => md5($imageUrl), 'url' => $imageUrl]));

    $response->assertNotFound();
    Http::assertNothingSent();
});
