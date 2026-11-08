<?php

declare(strict_types=1);

namespace App\Services\Music\Contracts;

use App\Enums\CatalogFilter;
use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Data\CatalogAlbum;
use App\Services\Music\Data\CatalogArtist;
use App\Services\Music\Data\CatalogResults;
use App\Services\Music\Data\RemotePlaylist;

/**
 * Capability: search the provider's public catalog and read artist, album and public playlist pages.
 */
interface BrowsesCatalog
{
    /**
     * @throws ProviderException
     */
    public function search(string $query, ?CatalogFilter $filter = null, int $limit = 20): CatalogResults;

    /**
     * @throws ProviderException
     */
    public function artist(string $artistId): CatalogArtist;

    /**
     * @throws ProviderException
     */
    public function album(string $albumId): CatalogAlbum;

    /**
     * @throws ProviderException
     */
    public function publicPlaylist(string $playlistId): RemotePlaylist;
}
