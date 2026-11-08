<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\CatalogPlaylistData;
use App\Data\PublicPlaylistPageData;
use App\Exceptions\Providers\ProviderException;
use App\Models\User;

/**
 * Composes a public playlist page for one user.
 */
final readonly class BuildPublicPlaylistPage
{
    public function __construct(
        private DescribePublicPlaylist $describePublicPlaylist,
        private BuildCatalogTracks $buildCatalogTracks,
    ) {}

    /**
     * @throws ProviderException
     */
    public function handle(User $user, string $playlistId): PublicPlaylistPageData
    {
        $playlist = $this->describePublicPlaylist->handle($playlistId);

        return new PublicPlaylistPageData(
            playlist: CatalogPlaylistData::fromRemote($playlist->summary),
            description: $playlist->summary->description,
            tracks: $this->buildCatalogTracks->handle($user, $playlist->tracks),
        );
    }
}
