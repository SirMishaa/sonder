<?php

declare(strict_types=1);

namespace App\Services\Music\Contracts;

use App\Exceptions\Providers\ProviderException;
use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\Data\RemotePlaylistSummary;

/**
 * Capability: list the account's playlists and read one with its tracks.
 * System playlists that are not music are left out by the adapter.
 */
interface ReadsPlaylists
{
    /**
     * @return list<RemotePlaylistSummary>
     *
     * @throws ProviderException
     */
    public function playlists(ProviderCredentials $credentials): array;

    /**
     * @param  int|null  $trackCountHint  Count from the listing, used when the playlist itself reports none.
     *
     * @throws ProviderException
     */
    public function playlist(ProviderCredentials $credentials, string $externalId, ?int $trackCountHint = null): RemotePlaylist;
}
