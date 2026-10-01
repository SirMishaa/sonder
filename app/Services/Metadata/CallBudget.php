<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Spends one call of a named limiter (defined in AppServiceProvider). Over
 * budget, the call is refused at once rather than waited for: sleeping would
 * hold a queue worker.
 */
final readonly class CallBudget
{
    public const string CREDITS_FM = 'credits-fm';

    public const string MUSICBRAINZ = 'musicbrainz';

    public function __construct(
        private RateLimiter $limiter,
        private EnrichmentTelemetry $telemetry,
    ) {}

    /**
     * @return int|null null when the call is allowed (and counted), else the seconds to wait
     */
    public function spend(string $limiter, string $key = 'global'): ?int
    {
        $limits = $this->limits($limiter, $key);

        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit['key'], $limit['maxAttempts'])) {
                $this->telemetry->refusal($limiter);

                return max(1, $this->limiter->availableIn($limit['key']));
            }
        }

        foreach ($limits as $limit) {
            $this->limiter->hit($limit['key'], $limit['decaySeconds']);
        }

        return null;
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
