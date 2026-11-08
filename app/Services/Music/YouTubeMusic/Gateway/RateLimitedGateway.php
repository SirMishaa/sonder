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
use SensitiveParameter;

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

    public const string CATALOG_LIMITER = 'youtube-music-catalog';

    private const string CATALOG_KEY = 'catalog';

    public function __construct(
        private YouTubeMusicGateway $gateway,
        private RateLimiter $limiter,
    ) {}

    public function account(#[SensitiveParameter] string $cookie): object
    {
        $this->spendCall(self::LIMITER, $cookie);

        return $this->gateway->account($cookie);
    }

    public function library(#[SensitiveParameter] string $cookie): array
    {
        $this->spendCall(self::LIMITER, $cookie);

        return $this->gateway->library($cookie);
    }

    public function playlist(#[SensitiveParameter] string $cookie, string $playlistId): object
    {
        $this->spendCall(self::LIMITER, $cookie);

        return $this->gateway->playlist($cookie, $playlistId);
    }

    public function search(string $query, ?string $filter, int $limit): array
    {
        $this->spendCall(self::CATALOG_LIMITER, self::CATALOG_KEY);

        return $this->gateway->search($query, $filter, $limit);
    }

    public function artist(string $channelId): object
    {
        $this->spendCall(self::CATALOG_LIMITER, self::CATALOG_KEY);

        return $this->gateway->artist($channelId);
    }

    public function album(string $browseId): object
    {
        $this->spendCall(self::CATALOG_LIMITER, self::CATALOG_KEY);

        return $this->gateway->album($browseId);
    }

    public function publicPlaylist(string $playlistId): object
    {
        $this->spendCall(self::CATALOG_LIMITER, self::CATALOG_KEY);

        return $this->gateway->publicPlaylist($playlistId);
    }

    /**
     * Spends one call of the named limiter: per account (hashed cookie) for
     * account calls, against the shared `catalog` key for anonymous ones.
     *
     * @throws ProviderRateLimited
     */
    private function spendCall(string $name, #[SensitiveParameter] string $subject): void
    {
        $limits = $this->limits($name, $name === self::CATALOG_LIMITER ? $subject : mb_substr(hash('sha256', $subject), 0, 16));

        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit['key'], $limit['maxAttempts'])) {
                resolve(EnrichmentTelemetry::class)->refusal($name);

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
    private function limits(string $name, string $account): array
    {
        $limiter = $this->limiter->limiter($name)
            ?? throw new LogicException('The ['.$name.'] rate limiter is not defined.');

        $limits = [];

        foreach (Arr::wrap($limiter($account)) as $limit) {
            if ($limit instanceof Limit && is_string($limit->key)) {
                $limits[] = ['key' => $limit->key, 'maxAttempts' => $limit->maxAttempts, 'decaySeconds' => $limit->decaySeconds];
            }
        }

        return $limits;
    }
}
