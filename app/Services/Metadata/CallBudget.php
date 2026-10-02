<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Arr;
use Illuminate\Support\Sleep;
use LogicException;

/**
 * Spends one call of a named limiter (defined in AppServiceProvider). Over
 * budget, a short wait is slept through in place; a long one is refused so
 * the queue worker is not held.
 */
final readonly class CallBudget
{
    public const string CREDITS_FM = 'credits-fm';

    public const string MUSICBRAINZ = 'musicbrainz';

    public const string LASTFM = 'lastfm';

    public function __construct(
        private RateLimiter $limiter,
        private EnrichmentTelemetry $telemetry,
    ) {}

    /**
     * @return int|null null when the call is allowed (and counted), else the seconds until every limit allows it
     */
    public function spend(string $limiter, string $key = 'global'): ?int
    {
        $limits = $this->limits($limiter, $key);

        $wait = 0;

        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit['key'], $limit['maxAttempts'])) {
                $wait = max($wait, 1, $this->limiter->availableIn($limit['key']));
            }
        }

        if ($wait > 0) {
            $this->telemetry->refusal($limiter);

            return $wait;
        }

        foreach ($limits as $limit) {
            $this->limiter->hit($limit['key'], $limit['decaySeconds']);
        }

        return null;
    }

    /**
     * Spends a call, sleeping through a wait of at most `$maxWaitSeconds`
     * (a per-second limiter) instead of refusing it.
     *
     * @return int|null null when the call is allowed (and counted), else the seconds to wait
     */
    public function await(string $limiter, string $key = 'global', int $maxWaitSeconds = 2): ?int
    {
        $wait = $this->spend($limiter, $key);

        if ($wait === null || $wait > $maxWaitSeconds) {
            return $wait;
        }

        Sleep::for($wait)->seconds();
        $this->telemetry->throttled($limiter, $wait);

        return $this->spend($limiter, $key);
    }

    /**
     * @return list<array{key: string, maxAttempts: int, decaySeconds: int}>
     */
    private function limits(string $name, string $key): array
    {
        $limiter = $this->limiter->limiter($name)
            ?? throw new LogicException("The [{$name}] rate limiter is not defined.");

        $limits = [];

        foreach (Arr::wrap($limiter($key)) as $limit) {
            if ($limit instanceof Limit && is_string($limit->key)) {
                $limits[] = ['key' => $limit->key, 'maxAttempts' => $limit->maxAttempts, 'decaySeconds' => $limit->decaySeconds];
            }
        }

        return $limits;
    }
}
