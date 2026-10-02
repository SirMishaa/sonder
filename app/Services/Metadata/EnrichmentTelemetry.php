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

    /**
     * A worker slept in place to stay within a per-second limit.
     */
    public function throttled(string $limiter, int $seconds): void;

    /**
     * One run of a resolution job: how far it got and what stopped it
     * (done, budget, or rate_limited:<source>). The idle time is how long
     * the job waited in the queue since its previous run, when known.
     */
    public function resolutionRun(string $outcome, int $settled, int $remaining, float $seconds, ?float $idleSeconds): void;

    public function coverage(string $facet, float $ratio): void;

    public function backlog(string $status, int $count): void;
}
