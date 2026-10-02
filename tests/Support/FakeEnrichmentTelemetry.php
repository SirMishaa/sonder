<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use App\Services\Metadata\EnrichmentTelemetry;

final class FakeEnrichmentTelemetry implements EnrichmentTelemetry
{
    /** @var list<array{source: string, endpoint: string, outcome: string}> */
    public array $lookups = [];

    /** @var list<array{status: string, method: string|null, confidence: float|null}> */
    public array $resolutions = [];

    /** @var list<string> */
    public array $refusals = [];

    /** @var array<string, int> seconds slept per limiter */
    public array $throttled = [];

    /** @var list<array{outcome: string, settled: int, remaining: int}> */
    public array $runs = [];

    /** @var array<string, float> */
    public array $coverage = [];

    /** @var array<string, int> */
    public array $backlog = [];

    public function lookup(MetadataSource $source, string $endpoint, LookupOutcome $outcome, float $seconds): void
    {
        $this->lookups[] = ['source' => $source->value, 'endpoint' => $endpoint, 'outcome' => $outcome->value];
    }

    public function resolution(ResolutionStatus $status, ?ResolutionMethod $method, ?float $confidence): void
    {
        $this->resolutions[] = ['status' => $status->value, 'method' => $method?->value, 'confidence' => $confidence];
    }

    public function refusal(string $limiter): void
    {
        $this->refusals[] = $limiter;
    }

    public function throttled(string $limiter, int $seconds): void
    {
        $this->throttled[$limiter] = ($this->throttled[$limiter] ?? 0) + $seconds;
    }

    public function resolutionRun(string $outcome, int $settled, int $remaining, float $seconds, ?float $idleSeconds): void
    {
        $this->runs[] = ['outcome' => $outcome, 'settled' => $settled, 'remaining' => $remaining];
    }

    public function coverage(string $facet, float $ratio): void
    {
        $this->coverage[$facet] = $ratio;
    }

    public function backlog(string $status, int $count): void
    {
        $this->backlog[$status] = $count;
    }
}
