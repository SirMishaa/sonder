<?php

declare(strict_types=1);

namespace App\Services\Music\YouTubeMusic\Gateway;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderRateLimited;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Caps the calls made to YouTube Music for each account against the
 * `youtube-music` limiter (see AppServiceProvider), so a bug or a burst of
 * syncs can never hammer the private API hard enough to get the account
 * blocked. Over budget, a call is refused at once rather than waited for:
 * sleeping would hold a queue or Octane worker for up to an hour.
 */
final readonly class RateLimitedGateway implements YouTubeMusicGateway
{
    public const string LIMITER = 'youtube-music';

    public function __construct(
        private YouTubeMusicGateway $gateway,
        private RateLimiter $limiter,
    ) {}

    public function account(string $cookie): object
    {
        $this->spendCall($cookie);

        return $this->gateway->account($cookie);
    }

    public function library(string $cookie): array
    {
        $this->spendCall($cookie);

        return $this->gateway->library($cookie);
    }

    public function playlist(string $cookie, string $playlistId): object
    {
        $this->spendCall($cookie);

        return $this->gateway->playlist($cookie, $playlistId);
    }

    /**
     * @throws ProviderRateLimited
     */
    private function spendCall(string $cookie): void
    {
        $limits = $this->limits(mb_substr(hash('sha256', $cookie), 0, 16));

        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit['key'], $limit['maxAttempts'])) {
                resolve(EnrichmentTelemetry::class)->refusal(self::LIMITER);

                throw new ProviderRateLimited(Provider::YouTubeMusic, $this->limiter->availableIn($limit['key']));
            }
        }

        foreach ($limits as $limit) {
            $this->limiter->hit($limit['key'], $limit['decaySeconds']);
        }
    }

    /**
     * @return list<array{key: string, maxAttempts: int, decaySeconds: int}>
     */
    private function limits(string $account): array
    {
        $limiter = $this->limiter->limiter(self::LIMITER)
            ?? throw new LogicException('The ['.self::LIMITER.'] rate limiter is not defined.');

        $limits = [];

        foreach (Arr::wrap($limiter($account)) as $limit) {
            if ($limit instanceof Limit && is_string($limit->key)) {
                $limits[] = ['key' => $limit->key, 'maxAttempts' => $limit->maxAttempts, 'decaySeconds' => $limit->decaySeconds];
            }
        }

        return $limits;
    }
}
