<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Data\CatalogAlbum;
use Illuminate\Support\Facades\Cache;

/**
 * Counts the tracks of albums through the album cache, leaving out any that cannot be read.
 * At most a dozen uncached albums are fetched per call, so a long discography cannot spend the shared catalog budget.
 */
final readonly class CountAlbumTracks
{
    public function __construct(private DescribeAlbum $describeAlbum) {}

    /**
     * @param  list<string>  $albumIds
     * @param  bool  $cachedOnly  Never calls the provider; only albums already cached are counted.
     * @param  int  $maxUncached  Albums beyond this many provider fetches are counted only when cached; ids in display order.
     * @return array<string, int>
     */
    public function handle(array $albumIds, bool $cachedOnly = false, int $maxUncached = 12): array
    {
        $counts = [];
        $fetched = 0;

        foreach ($albumIds as $albumId) {
            $album = Cache::get(DescribeAlbum::cacheKey($albumId));

            if ($album instanceof CatalogAlbum) {
                $counts[$albumId] = $album->trackCount;

                continue;
            }

            if ($cachedOnly || $fetched >= $maxUncached) {
                continue;
            }

            $fetched++;

            try {
                $counts[$albumId] = $this->describeAlbum->handle($albumId)->trackCount;
            } catch (ProviderException) {
                continue;
            }
        }

        return $counts;
    }
}
