<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic;

use App\Enums\CatalogFilter;
use App\Enums\Provider;
use App\Exceptions\Providers\CredentialsRejected;
use App\Services\Music\Contracts\BrowsesCatalog;
use App\Services\Music\Contracts\ProviderAdapter;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\Contracts\ReadsPlaylists;
use App\Services\Music\Data\CatalogAlbum;
use App\Services\Music\Data\CatalogArtist;
use App\Services\Music\Data\CatalogResults;
use App\Services\Music\Data\RemoteAccount;
use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\YouTubeMusic\Gateway\YouTubeMusicGateway;
use LogicException;

/**
 * YouTube Music behind Sonder's provider contracts.
 */
final readonly class YouTubeMusicAdapter implements BrowsesCatalog, ProviderAdapter, ReadsPlaylists
{
    /**
     * Library entries that are not music. "Liked Music" (LM) stays listed
     * until favourites exist (multi-provider plan 2).
     */
    public const array SYSTEM_PLAYLIST_IDS = ['SE'];

    public function __construct(
        private YouTubeMusicGateway $gateway,
        private YouTubeMusicMapper $mapper,
    ) {}

    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    public function account(ProviderCredentials $credentials): RemoteAccount
    {
        return $this->mapper->account($this->gateway->account($this->cookie($credentials)));
    }

    /**
     * A signed-in library always lists "Liked Music", so an empty raw listing
     * means YouTube Music served the session signed out. Checked before
     * filtering: a library of system playlists only is still a valid one.
     */
    public function playlists(ProviderCredentials $credentials): array
    {
        $library = $this->gateway->library($this->cookie($credentials));

        if ($library === []) {
            throw new CredentialsRejected(Provider::YouTubeMusic, 'an empty library means the session is signed out');
        }

        $summaries = [];

        foreach ($library as $entry) {
            $summary = $this->mapper->playlistSummary($entry);

            if ($summary !== null && ! in_array($summary->ref->externalId, self::SYSTEM_PLAYLIST_IDS, true)) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }

    public function playlist(ProviderCredentials $credentials, string $externalId, ?int $trackCountHint = null): RemotePlaylist
    {
        return $this->mapper->playlist(
            $this->gateway->playlist($this->cookie($credentials), $externalId),
            $externalId,
            $trackCountHint,
        );
    }

    public function search(string $query, ?CatalogFilter $filter = null, int $limit = 20): CatalogResults
    {
        return $this->mapper->searchResults($this->gateway->search($query, match ($filter) {
            CatalogFilter::Tracks => 'songs',
            null => null,
            default => $filter->value,
        }, $limit));
    }

    public function artist(string $artistId): CatalogArtist
    {
        return $this->mapper->artist($this->gateway->artist($artistId), $artistId);
    }

    public function album(string $albumId): CatalogAlbum
    {
        return $this->mapper->album($this->gateway->album($albumId), $albumId);
    }

    public function publicPlaylist(string $playlistId): RemotePlaylist
    {
        return $this->mapper->playlist($this->gateway->publicPlaylist($playlistId), $playlistId);
    }

    private function cookie(ProviderCredentials $credentials): string
    {
        if (! $credentials instanceof YouTubeMusicCredentials) {
            throw new LogicException('YouTube Music received credentials of another provider: '.$credentials::class);
        }

        return $credentials->cookie;
    }
}
