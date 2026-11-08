<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Contracts\BrowsesCatalog;
use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\ProviderRegistry;
use App\Support\CatalogCache;

/**
 * Reads a public catalog playlist, remembering it for a day.
 */
final readonly class DescribePublicPlaylist
{
    public function __construct(private ProviderRegistry $providers) {}

    /**
     * @throws ProviderException
     */
    public function handle(string $playlistId): RemotePlaylist
    {
        return CatalogCache::remember(
            "catalog:playlist:{$playlistId}",
            now()->addDay(),
            fn (): RemotePlaylist => $this->providers
                ->require(Provider::YouTubeMusic, BrowsesCatalog::class)
                ->publicPlaylist($playlistId),
        );
    }
}
