<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\AccountData;
use App\Data\PlaylistData;
use App\Data\PlaylistSummaryData;
use App\Data\TrackData;
use App\Exceptions\YouTubeMusicException;
use App\Exceptions\YouTubeMusicRateLimitedException;
use App\Services\YouTubeMusic\Client;
use RuntimeException;

/**
 * Stands in for YouTube Music throughout the test suite.
 *
 * This is bound in TestCase for every test, so no test can reach the real
 * service by accident. That guard has to live here rather than rely on
 * `Http::preventStrayRequests()`, because the underlying package ships its own
 * HTTP layer and never touches Laravel's client.
 */
final class FakeYouTubeMusicClient implements Client
{
    public bool $shouldFail = false;

    /** Seconds until the next call is allowed; null when calls go through. */
    public ?int $rateLimitedFor = null;

    public ?AccountData $account = null;

    /** @var array<int, PlaylistSummaryData> */
    public array $playlists = [];

    /** @var array<string, PlaylistData> */
    public array $tracks = [];

    /** @var array<int, array{method: string, cookie: string, playlistId?: string, trackCount?: int|null}> */
    public array $calls = [];

    public static function anAccount(string $name = 'Test Listener'): AccountData
    {
        return new AccountData(
            name: $name,
            channelId: 'UC0000000000000000000000',
            thumbnailUrl: 'https://example.test/avatar.jpg',
            isPremium: true,
        );
    }

    public static function aPlaylistSummary(
        string $id = 'PL_TEST',
        string $title = 'Deep Focus',
        ?int $trackCount = 12,
    ): PlaylistSummaryData {
        return new PlaylistSummaryData(
            id: $id,
            title: $title,
            description: 'Instrumental tracks for long sessions',
            trackCount: $trackCount,
            thumbnailUrl: 'https://example.test/cover.jpg',
            author: 'Test Listener',
        );
    }

    /**
     * @param  array<int, TrackData>|null  $tracks
     */
    public static function aPlaylist(
        string $id = 'PL_TEST',
        string $title = 'Deep Focus',
        int $trackCount = 1,
        ?array $tracks = null,
    ): PlaylistData {
        return new PlaylistData(
            id: $id,
            title: $title,
            description: 'Instrumental tracks for long sessions',
            trackCount: $trackCount,
            duration: '48 minutes',
            thumbnailUrl: 'https://example.test/cover.jpg',
            author: 'Test Listener',
            tracks: $tracks ?? [self::aTrack()],
        );
    }

    public static function aTrack(string $title = 'Rendezvous', ?string $videoId = 'xXp4GnC1Z3Q'): TrackData
    {
        return new TrackData(
            videoId: $videoId,
            title: $title,
            artists: 'Ludwig Göransson',
            album: 'The Mandalorian',
            duration: '3:19',
            durationSeconds: 199,
            thumbnailUrl: 'https://example.test/track.jpg',
            isExplicit: false,
            isAvailable: true,
        );
    }

    public function account(string $cookie): AccountData
    {
        $this->record(['method' => 'account', 'cookie' => $cookie]);

        return $this->account ?? self::anAccount();
    }

    public function playlists(string $cookie): array
    {
        $this->record(['method' => 'playlists', 'cookie' => $cookie]);

        return $this->playlists;
    }

    public function playlist(string $cookie, string $playlistId, ?int $trackCount = null): PlaylistData
    {
        $this->record([
            'method' => 'playlist',
            'cookie' => $cookie,
            'playlistId' => $playlistId,
            'trackCount' => $trackCount,
        ]);

        return $this->tracks[$playlistId] ?? throw new RuntimeException(
            "The fake client has no playlist registered for [{$playlistId}]."
        );
    }

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @param  array{method: string, cookie: string, playlistId?: string, trackCount?: int|null}  $call
     */
    private function record(array $call): void
    {
        $this->calls[] = $call;

        if ($this->shouldFail) {
            throw YouTubeMusicException::unreachable(new RuntimeException('Faked failure.'));
        }

        if ($this->rateLimitedFor !== null) {
            throw new YouTubeMusicRateLimitedException($this->rateLimitedFor);
        }
    }
}
