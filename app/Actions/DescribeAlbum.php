<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Contracts\BrowsesCatalog;
use App\Services\Music\Data\CatalogAlbum;
use App\Services\Music\ProviderRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a catalog album page, remembering it for a day.
 */
final readonly class DescribeAlbum
{
    public function __construct(private ProviderRegistry $providers) {}

    public static function cacheKey(string $albumId): string
    {
        return "catalog:album:{$albumId}";
    }

    /**
     * @throws ProviderException
     */
    public function handle(string $albumId): CatalogAlbum
    {
        return Cache::remember(
            self::cacheKey($albumId),
            now()->addDay(),
            fn (): CatalogAlbum => $this->providers
                ->require(Provider::YouTubeMusic, BrowsesCatalog::class)
                ->album($albumId),
        );
    }
}
