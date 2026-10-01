<?php

declare(strict_types=1);

namespace App\Exceptions\Metadata;

use App\Enums\MetadataSource;
use RuntimeException;

/**
 * Sonder's own budget for a metadata service is spent, or the service asked
 * to slow down. The job releases itself; never shown to a user, never
 * reported.
 */
final class MetadataSourceRateLimited extends RuntimeException
{
    public function __construct(public readonly MetadataSource $source, public readonly int $retryAfter)
    {
        parent::__construct("Too many calls to {$source->value}; the next one is allowed in {$retryAfter} seconds.");
    }
}
