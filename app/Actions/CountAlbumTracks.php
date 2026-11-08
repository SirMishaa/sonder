<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Data\CatalogAlbum;
use Illuminate\Support\Facades\Cache;

/**
 * Counts the tracks of albums through the album cache, leaving out any that cannot be read.
 */
final readonly class CountAlbumTracks
{
    public function __construct(private DescribeAlbum $describeAlbum) {}

    /**
     * @param  list<string>  $albumIds
     * @param  bool  $cachedOnly  Never calls the provider; only albums already cached are counted.
     * @return array<string, int>
     */
    public function handle(array $albumIds, bool $cachedOnly = false): array
    {
        $counts = [];

        foreach ($albumIds as $albumId) {
            if ($cachedOnly) {
                $album = Cache::get(DescribeAlbum::cacheKey($albumId));

                if ($album instanceof CatalogAlbum) {
                    $counts[$albumId] = $album->trackCount;
                }

                continue;
            }

            try {
                $counts[$albumId] = $this->describeAlbum->handle($albumId)->trackCount;
            } catch (ProviderException) {
                continue;
            }
        }

        return $counts;
    }
}
