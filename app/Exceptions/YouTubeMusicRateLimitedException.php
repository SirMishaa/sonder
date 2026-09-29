<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Sonder's own call budget towards YouTube Music is spent. Deliberately not
 * a YouTubeMusicException: nothing is wrong with the cookie, so catching
 * this must never flag it as expired.
 */
final class YouTubeMusicRateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Too many calls to YouTube Music; the next one is allowed in {$retryAfter} seconds.");
    }
}
