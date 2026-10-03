<?php

declare(strict_types=1);

use App\Models\Playlist;
use App\Services\Thumbnails\ThumbnailProxy;
use Illuminate\Database\Migrations\Migration;

function nameThumbnailUrlsLikeFiles(): void
{
    $migration = require database_path('migrations/2026_10_03_000719_name_thumbnail_urls_like_files.php');

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new LogicException('The migration cannot be run.');
    }

    $migration->up();
}

/**
 * A proxied URL as stored before it ended like a file.
 */
function legacyThumbnailUrl(string $imageUrl): string
{
    return route('thumbnail.show', ['hash' => md5($imageUrl), 'url' => $imageUrl]);
}

it('renames the stored thumbnails of tracks and playlists like files', function (): void {
    $trackImage = 'https://yt3.googleusercontent.com/track=w120-h120-l90-rj';
    $playlistImage = 'https://i.ytimg.com/vi/abc/hqdefault.webp';
    $playlist = Playlist::factory()->create(['thumbnail_url' => legacyThumbnailUrl($playlistImage)]);
    $track = $playlist->tracks()->create(['title' => 'Survival', 'artists' => 'Muse', 'thumbnail_url' => legacyThumbnailUrl($trackImage)]);

    nameThumbnailUrlsLikeFiles();

    expect($track->fresh()?->thumbnail_url)->toBe(ThumbnailProxy::url($trackImage))
        ->and($playlist->fresh()?->thumbnail_url)->toBe(ThumbnailProxy::url($playlistImage));
});

it('leaves missing and already renamed thumbnails alone', function (): void {
    $renamed = ThumbnailProxy::url('https://i.ytimg.com/vi/abc/hqdefault.jpg');
    $playlist = Playlist::factory()->create();
    $missing = $playlist->tracks()->create(['title' => 'Survival', 'artists' => 'Muse', 'thumbnail_url' => null]);
    $current = $playlist->tracks()->create(['title' => 'Uprising', 'artists' => 'Muse', 'thumbnail_url' => $renamed]);

    nameThumbnailUrlsLikeFiles();

    expect($missing->fresh()?->thumbnail_url)->toBeNull()
        ->and($current->fresh()?->thumbnail_url)->toBe($renamed);
});
