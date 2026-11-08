<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exceptions\Providers\ProviderException;
use App\Services\Music\YouTubeMusic\Gateway\YouTubeMusicGateway;
use RuntimeException;

/**
 * Raw-payload stand-in for ytmusicapi, for the adapter's own tests.
 */
final class FakeYouTubeMusicGateway implements YouTubeMusicGateway
{
    public object $account;

    /** @var list<object> */
    public array $library = [];

    /** @var array<string, object> */
    public array $playlists = [];

    /** @var array<string, list<object>> */
    public array $searches = [];

    /** @var array<string, object> */
    public array $artists = [];

    /** @var array<string, object> */
    public array $albums = [];

    /** @var array<string, object> */
    public array $publicPlaylists = [];

    public ?ProviderException $failure = null;

    /** @var list<array{method: string, cookie: string, playlistId?: string, id?: string}> */
    public array $calls = [];

    public function __construct()
    {
        $this->account = (object) ['name' => 'Mishaa', 'channelId' => 'UC123', 'is_premium' => true];
    }

    public function account(string $cookie): object
    {
        $this->record(['method' => 'account', 'cookie' => $cookie]);

        return $this->account;
    }

    public function library(string $cookie): array
    {
        $this->record(['method' => 'library', 'cookie' => $cookie]);

        return $this->library;
    }

    public function playlist(string $cookie, string $playlistId): object
    {
        $this->record(['method' => 'playlist', 'cookie' => $cookie, 'playlistId' => $playlistId]);

        return $this->playlists[$playlistId] ?? throw new RuntimeException("No raw playlist registered for [{$playlistId}].");
    }

    public function search(string $query, ?string $filter, int $limit): array
    {
        $this->record(['method' => 'search', 'cookie' => '', 'id' => $query]);

        return $this->searches[$query] ?? [];
    }

    public function artist(string $channelId): object
    {
        $this->record(['method' => 'artist', 'cookie' => '', 'id' => $channelId]);

        return $this->artists[$channelId] ?? throw new RuntimeException("No raw artist registered for [{$channelId}].");
    }

    public function album(string $browseId): object
    {
        $this->record(['method' => 'album', 'cookie' => '', 'id' => $browseId]);

        return $this->albums[$browseId] ?? throw new RuntimeException("No raw album registered for [{$browseId}].");
    }

    public function publicPlaylist(string $playlistId): object
    {
        $this->record(['method' => 'publicPlaylist', 'cookie' => '', 'id' => $playlistId]);

        return $this->publicPlaylists[$playlistId] ?? throw new RuntimeException("No raw public playlist registered for [{$playlistId}].");
    }

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @param  array{method: string, cookie: string, playlistId?: string, id?: string}  $call
     */
    private function record(array $call): void
    {
        $this->calls[] = $call;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
