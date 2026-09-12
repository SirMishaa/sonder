<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

it('serves a thumbnail after downloading it', function (): void {
    $imageUrl = 'https://example.com/image.jpg';
    $hash = md5($imageUrl);

    Http::fake([
        $imageUrl => Http::response('fake-image-content', 200),
    ]);

    $response = $this->get(route('thumbnail.show', ['hash' => $hash, 'url' => $imageUrl]));

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'image/jpeg');

    expect($response->content())->toBe('fake-image-content');
});

it('returns 404 when hash does not match URL', function (): void {
    $imageUrl = 'https://example.com/image.jpg';
    $wrongHash = 'wrong-hash';

    $response = $this->get(route('thumbnail.show', ['hash' => $wrongHash, 'url' => $imageUrl]));

    $response->assertNotFound();
});

it('returns 404 when URL is missing', function (): void {
    $response = $this->get(route('thumbnail.show', ['hash' => 'some-hash']));

    $response->assertNotFound();
});

it('returns 404 when download fails', function (): void {
    $imageUrl = 'https://example.com/image.jpg';
    $hash = md5($imageUrl);

    Http::fake([
        $imageUrl => Http::response('', 404),
    ]);

    $response = $this->get(route('thumbnail.show', ['hash' => $hash, 'url' => $imageUrl]));

    $response->assertNotFound();
});
