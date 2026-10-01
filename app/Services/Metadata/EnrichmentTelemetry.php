<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;

/**
 * Metrics, span attributes and logs of the enrichment pipeline.
 */
interface EnrichmentTelemetry
{
    public function lookup(MetadataSource $source, string $endpoint, LookupOutcome $outcome, float $seconds): void;

    public function resolution(ResolutionStatus $status, ?ResolutionMethod $method, ?float $confidence): void;

    public function refusal(string $limiter): void;

    public function coverage(string $facet, float $ratio): void;

    public function backlog(string $status, int $count): void;
}
