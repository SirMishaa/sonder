<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Contracts\BrowsesCatalog;
use App\Services\Music\Data\CatalogArtist;
use App\Services\Music\ProviderRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a catalog artist page, remembering it for a day.
 */
final readonly class DescribeArtist
{
    public function __construct(private ProviderRegistry $providers) {}

    /**
     * @throws ProviderException
     */
    public function handle(string $artistId): CatalogArtist
    {
        return Cache::remember(
            "catalog:artist:{$artistId}",
            now()->addDay(),
            fn (): CatalogArtist => $this->providers
                ->require(Provider::YouTubeMusic, BrowsesCatalog::class)
                ->artist($artistId),
        );
    }
}
