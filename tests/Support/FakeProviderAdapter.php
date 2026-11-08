<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ArtistRole;
use App\Enums\CatalogFilter;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderRateLimited;
use App\Exceptions\Providers\ProviderUnavailable;
use App\Services\Music\Contracts\BrowsesCatalog;
use App\Services\Music\Contracts\ProviderAdapter;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\Contracts\ReadsPlaylists;
use App\Services\Music\Data\CatalogAlbum;
use App\Services\Music\Data\CatalogAlbumSummary;
use App\Services\Music\Data\CatalogArtist;
use App\Services\Music\Data\CatalogArtistSummary;
use App\Services\Music\Data\CatalogResults;
use App\Services\Music\Data\ProviderRef;
use App\Services\Music\Data\RemoteAccount;
use App\Services\Music\Data\RemoteAlbum;
use App\Services\Music\Data\RemoteArtist;
use App\Services\Music\Data\RemotePlaylist;
use App\Services\Music\Data\RemotePlaylistSummary;
use App\Services\Music\Data\RemoteTrack;
use RuntimeException;

/**
 * Stands in for every provider throughout the suite.
 *
 * Registered in TestCase for every test, so no test can reach a real service
 * by accident. That guard has to live here rather than rely on
 * `Http::preventStrayRequests()`: ytmusicapi ships its own HTTP layer.
 */
final class FakeProviderAdapter implements BrowsesCatalog, ProviderAdapter, ReadsPlaylists
{
    /** Throws CredentialsRejected on every call (an expired cookie). */
    public bool $shouldFail = false;

    /** Throws ProviderUnavailable on every call (an outage). */
    public bool $unavailable = false;

    /** Seconds until the next call is allowed; null when calls go through. */
    public ?int $rateLimitedFor = null;

    public ?RemoteAccount $account = null;

    /** @var list<RemotePlaylistSummary> */
    public array $playlists = [];

    /** @var array<string, RemotePlaylist> */
    public array $tracks = [];

    public ?CatalogResults $searchResults = null;

    /** @var array<string, CatalogArtist> */
    public array $artists = [];

    /** @var array<string, CatalogAlbum> */
    public array $albums = [];

    /** @var array<string, RemotePlaylist> */
    public array $publicPlaylists = [];

    /** @var list<array{method: string, credentials?: array<string, string>, playlistId?: string, trackCount?: int|null, query?: string, filter?: CatalogFilter|null, limit?: int, artistId?: string, albumId?: string}> */
    public array $calls = [];

    public static function anAccount(string $name = 'Test Listener'): RemoteAccount
    {
        return new RemoteAccount(new ProviderRef(Provider::YouTubeMusic, 'UC0000000000000000000000'), $name);
    }

    public static function aPlaylistSummary(string $id = 'PL_TEST', string $title = 'Deep Focus', ?int $trackCount = 12): RemotePlaylistSummary
    {
        return new RemotePlaylistSummary(
            ref: new ProviderRef(Provider::YouTubeMusic, $id !== '' ? $id : 'PL_TEST'),
            title: $title,
            description: 'Instrumental tracks for long sessions',
            trackCount: $trackCount,
            thumbnailUrl: 'https://example.test/cover.jpg',
            author: 'Test Listener',
        );
    }

    /**
     * @param  list<RemoteTrack>|null  $tracks
     */
    public static function aPlaylist(string $id = 'PL_TEST', string $title = 'Deep Focus', int $trackCount = 1, ?array $tracks = null): RemotePlaylist
    {
        return new RemotePlaylist(self::aPlaylistSummary($id, $title, $trackCount), $tracks ?? [self::aTrack()]);
    }

    public static function aTrack(string $title = 'Rendezvous', ?string $videoId = 'xXp4GnC1Z3Q'): RemoteTrack
    {
        return new RemoteTrack(
            ref: $videoId === null || $videoId === '' ? null : new ProviderRef(Provider::YouTubeMusic, $videoId),
            title: $title,
            artists: [new RemoteArtist(null, 'Ludwig Göransson', ArtistRole::Main)],
            album: new RemoteAlbum(null, 'The Mandalorian', null),
            durationSeconds: 199,
            isrc: null,
            kind: SourceKind::Audio,
            isExplicit: false,
            isAvailable: true,
            thumbnailUrl: 'https://example.test/track.jpg',
        );
    }

    /**
     * @param  non-empty-string  $id
     */
    public static function aCatalogArtist(string $id = 'UCs6GGpd9zvsYghuYe0VDFUQ', string $name = 'Rammstein'): CatalogArtist
    {
        $summary = self::aCatalogArtistSummary($id, $name);
        $album = self::aCatalogAlbumSummary();

        return new CatalogArtist(
            summary: $summary,
            monthlyListeners: '9.1M',
            views: 1200000,
            description: 'German industrial metal band.',
            topTracks: [self::aTrack()],
            albums: [$album],
            singles: [],
            videos: [],
            related: [],
        );
    }

    /**
     * @param  non-empty-string  $id
     */
    public static function aCatalogAlbum(string $id = 'MPREb_LJSVi8szMQL', string $title = 'Zeit', int $year = 2022, int $trackCount = 11): CatalogAlbum
    {
        $track = self::aTrack();

        return new CatalogAlbum(
            summary: self::aCatalogAlbumSummary($id, $title, $year),
            description: null,
            trackCount: $trackCount,
            durationSeconds: 2700,
            tracks: [$track],
            playsByVideoId: $track->ref !== null ? [$track->ref->externalId => 1000] : [],
        );
    }

    public static function aCatalogResults(): CatalogResults
    {
        return new CatalogResults(
            topArtist: self::aCatalogArtistSummary(),
            tracks: [self::aTrack()],
            albums: [self::aCatalogAlbumSummary()],
            artists: [self::aCatalogArtistSummary()],
            playlists: [],
        );
    }

    public function provider(): Provider
    {
        return Provider::YouTubeMusic;
    }

    public function account(ProviderCredentials $credentials): RemoteAccount
    {
        $this->record(['method' => 'account', 'credentials' => $credentials->toArray()]);

        return $this->account ?? self::anAccount();
    }

    public function playlists(ProviderCredentials $credentials): array
    {
        $this->record(['method' => 'playlists', 'credentials' => $credentials->toArray()]);

        return $this->playlists;
    }

    public function playlist(ProviderCredentials $credentials, string $externalId, ?int $trackCountHint = null): RemotePlaylist
    {
        $this->record(['method' => 'playlist', 'credentials' => $credentials->toArray(), 'playlistId' => $externalId, 'trackCount' => $trackCountHint]);

        return $this->tracks[$externalId] ?? throw new RuntimeException("The fake provider has no playlist registered for [{$externalId}].");
    }

    public function search(string $query, ?CatalogFilter $filter = null, int $limit = 20): CatalogResults
    {
        $this->record(['method' => 'search', 'query' => $query, 'filter' => $filter, 'limit' => $limit]);

        return $this->searchResults ?? new CatalogResults(null, [], [], [], []);
    }

    public function artist(string $artistId): CatalogArtist
    {
        $this->record(['method' => 'artist', 'artistId' => $artistId]);

        return $this->artists[$artistId] ?? throw new RuntimeException("The fake provider has no artist registered for [{$artistId}].");
    }

    public function album(string $albumId): CatalogAlbum
    {
        $this->record(['method' => 'album', 'albumId' => $albumId]);

        return $this->albums[$albumId] ?? throw new RuntimeException("The fake provider has no album registered for [{$albumId}].");
    }

    public function publicPlaylist(string $playlistId): RemotePlaylist
    {
        $this->record(['method' => 'publicPlaylist', 'playlistId' => $playlistId]);

        return $this->publicPlaylists[$playlistId] ?? throw new RuntimeException("The fake provider has no public playlist registered for [{$playlistId}].");
    }

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @param  non-empty-string  $id
     */
    private static function aCatalogArtistSummary(string $id = 'UCs6GGpd9zvsYghuYe0VDFUQ', string $name = 'Rammstein'): CatalogArtistSummary
    {
        return new CatalogArtistSummary(new ProviderRef(Provider::YouTubeMusic, $id), $name, 'https://example.test/artist.jpg', '4.2M');
    }

    /**
     * @param  non-empty-string  $id
     */
    private static function aCatalogAlbumSummary(string $id = 'MPREb_LJSVi8szMQL', string $title = 'Zeit', int $year = 2022): CatalogAlbumSummary
    {
        return new CatalogAlbumSummary(
            ref: new ProviderRef(Provider::YouTubeMusic, $id),
            title: $title,
            type: 'Album',
            year: $year,
            artists: [new RemoteArtist(null, 'Rammstein', ArtistRole::Main)],
            thumbnailUrl: 'https://example.test/album.jpg',
            isExplicit: false,
        );
    }

    /**
     * @param  array{method: string, credentials?: array<string, string>, playlistId?: string, trackCount?: int|null, query?: string, filter?: CatalogFilter|null, limit?: int, artistId?: string, albumId?: string}  $call
     */
    private function record(array $call): void
    {
        $this->calls[] = $call;

        if ($this->shouldFail) {
            throw new CredentialsRejected(Provider::YouTubeMusic, 'Faked refusal.');
        }

        if ($this->unavailable) {
            throw new ProviderUnavailable(Provider::YouTubeMusic, 'Faked outage.');
        }

        if ($this->rateLimitedFor !== null) {
            throw new ProviderRateLimited(Provider::YouTubeMusic, $this->rateLimitedFor);
        }
    }
}
