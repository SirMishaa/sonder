<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

final class OpenTelemetryEnrichmentTelemetry implements EnrichmentTelemetry
{
    public function lookup(MetadataSource $source, string $endpoint, LookupOutcome $outcome, float $seconds): void
    {
        Meter::counter('sonder.enrichment.lookups', '{lookup}', 'Metadata lookups, by source, endpoint and outcome')
            ->add(1, ['source' => $source->value, 'endpoint' => $endpoint, 'outcome' => $outcome->value]);
        Meter::histogram('sonder.enrichment.lookup.duration', 's', 'Time one metadata lookup took')
            ->record($seconds, ['source' => $source->value, 'endpoint' => $endpoint]);

        Tracer::activeSpan()->setAttributes([
            'sonder.enrichment.source' => $source->value,
            'sonder.enrichment.endpoint' => $endpoint,
            'sonder.enrichment.outcome' => $outcome->value,
        ]);

        if ($outcome === LookupOutcome::Failed) {
            Log::warning('Metadata lookup failed', ['source' => $source->value, 'endpoint' => $endpoint]);
        }
    }

    public function resolution(ResolutionStatus $status, ?ResolutionMethod $method, ?float $confidence): void
    {
        Meter::counter('sonder.enrichment.resolutions', '{resolution}', 'Provider tracks resolved to recordings, by method and outcome')
            ->add(1, [
                'outcome' => $status->value,
                'method' => $method->value ?? 'none',
                'confidence' => match (true) {
                    $confidence === null => 'none',
                    $confidence >= 0.9 => 'high',
                    default => 'medium',
                },
            ]);
    }

    public function refusal(string $limiter): void
    {
        Meter::counter('sonder.rate_limit.refusals', '{call}', 'Calls Sonder refused itself to stay within a rate limit')
            ->add(1, ['limiter' => $limiter]);
    }

    public function coverage(string $facet, float $ratio): void
    {
        Meter::gauge('sonder.enrichment.coverage', '1', 'Share of the library carrying a kind of metadata')
            ->record($ratio, ['facet' => $facet]);
    }

    public function backlog(string $status, int $count): void
    {
        Meter::gauge('sonder.enrichment.backlog', '{item}', 'Metadata waiting to be fetched again, by status')
            ->record($count, ['status' => $status]);
    }
}
