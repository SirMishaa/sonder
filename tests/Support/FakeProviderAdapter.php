<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ArtistRole;
use App\Enums\Provider;
use App\Enums\SourceKind;
use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderRateLimited;
use App\Exceptions\Providers\ProviderUnavailable;
use App\Services\Music\Contracts\ProviderAdapter;
use App\Services\Music\Contracts\ProviderCredentials;
use App\Services\Music\Contracts\ReadsPlaylists;
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
final class FakeProviderAdapter implements ProviderAdapter, ReadsPlaylists
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

    /** @var list<array{method: string, credentials: array<string, string>, playlistId?: string, trackCount?: int|null}> */
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

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @param  array{method: string, credentials: array<string, string>, playlistId?: string, trackCount?: int|null}  $call
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
