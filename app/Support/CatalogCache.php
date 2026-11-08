<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Fills catalog cache entries under a lock, so parallel requests for the same
 * cold key cost the provider one call instead of one each.
 */
final class CatalogCache
{
    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public static function remember(string $key, DateTimeInterface $ttl, Closure $callback): mixed
    {
        try {
            return Cache::lock("{$key}:lock", 10)->block(5, fn (): mixed => Cache::remember($key, $ttl, $callback));
        } catch (LockTimeoutException) {
            return Cache::remember($key, $ttl, $callback);
        }
    }
}
