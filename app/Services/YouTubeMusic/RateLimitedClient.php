<?php

declare(strict_types=1);

namespace App\Services\YouTubeMusic;

use App\Data\AccountData;
use App\Data\PlaylistData;
use App\Exceptions\YouTubeMusicRateLimitedException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Caps the calls made to YouTube Music for each account against the
 * `youtube-music` rate limiter (see AppServiceProvider), so a bug or a burst
 * of syncs can never hammer the private API hard enough to get the account
 * blocked.
 *
 * Over budget, a call is refused at once rather than waited for: sleeping
 * would hold a queue worker or an Octane worker for up to an hour. The
 * caller decides what to do with the delay (a job releases itself, a
 * request shows an error).
 */
final readonly class RateLimitedClient implements Client
{
    public const string LIMITER = 'youtube-music';

    public function __construct(
        private Client $client,
        private RateLimiter $limiter,
    ) {}

    public function account(string $cookie): AccountData
    {
        $this->spendCall($cookie);

        return $this->client->account($cookie);
    }

    public function playlists(string $cookie): array
    {
        $this->spendCall($cookie);

        return $this->client->playlists($cookie);
    }

    public function playlist(string $cookie, string $playlistId, ?int $trackCount = null): PlaylistData
    {
        $this->spendCall($cookie);

        return $this->client->playlist($cookie, $playlistId, $trackCount);
    }

    /**
     * @throws YouTubeMusicRateLimitedException
     */
    private function spendCall(string $cookie): void
    {
        $account = hash('sha256', $cookie)
            |> (fn ($x) => mb_substr($x, 0, 16));

        $limits = $this->limits($account);

        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                throw new YouTubeMusicRateLimitedException($this->limiter->availableIn($limit->key));
            }
        }

        foreach ($limits as $limit) {
            $this->limiter->hit($limit->key, $limit->decaySeconds);
        }
    }

    /**
     * @return array<int, Limit>
     */
    private function limits(string $account): array
    {
        $limiter = $this->limiter->limiter(self::LIMITER)
            ?? throw new LogicException('The ['.self::LIMITER.'] rate limiter is not defined.');

        return array_values(array_filter(
            Arr::wrap($limiter($account)),
            fn (mixed $limit): bool => $limit instanceof Limit,
        ));
    }
}
