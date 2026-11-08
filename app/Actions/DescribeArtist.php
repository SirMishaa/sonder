<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Contracts\BrowsesCatalog;
use App\Services\Music\Data\CatalogArtist;
use App\Services\Music\ProviderRegistry;
use App\Support\CatalogCache;

/**
 * Reads a catalog artist page, remembering it for a day.
 */
final readonly class DescribeArtist
{
    public function __construct(private ProviderRegistry $providers) {}

    public static function cacheKey(string $artistId): string
    {
        return "catalog:artist:{$artistId}";
    }

    /**
     * @throws ProviderException
     */
    public function handle(string $artistId): CatalogArtist
    {
        return CatalogCache::remember(
            self::cacheKey($artistId),
            now()->addDay(),
            fn (): CatalogArtist => $this->providers
                ->require(Provider::YouTubeMusic, BrowsesCatalog::class)
                ->artist($artistId),
        );
    }
}
