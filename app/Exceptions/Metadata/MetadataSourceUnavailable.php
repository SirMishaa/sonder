<?php

declare(strict_types=1);

namespace App\Exceptions\Metadata;

use App\Enums\MetadataSource;
use RuntimeException;

/**
 * A metadata service could not answer (network, 5xx, unexpected payload).
 */
final class MetadataSourceUnavailable extends RuntimeException
{
    public function __construct(public readonly MetadataSource $source, string $reason)
    {
        parent::__construct("{$source->value} is unavailable: {$reason}");
    }
}
