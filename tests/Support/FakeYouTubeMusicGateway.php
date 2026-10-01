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

    public ?ProviderException $failure = null;

    /** @var list<array{method: string, cookie: string, playlistId?: string}> */
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

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @param  array{method: string, cookie: string, playlistId?: string}  $call
     */
    private function record(array $call): void
    {
        $this->calls[] = $call;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
