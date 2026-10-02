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

    public function throttled(string $limiter, int $seconds): void
    {
        Meter::counter('sonder.rate_limit.throttled', 's', 'Seconds a worker slept to stay within a per-second limit')
            ->add($seconds, ['limiter' => $limiter]);
    }

    public function resolutionRun(string $outcome, int $settled, int $remaining, float $seconds, ?float $idleSeconds): void
    {
        $kind = str_starts_with($outcome, 'rate_limited') ? 'rate_limited' : $outcome;

        Meter::histogram('sonder.enrichment.resolution_run.duration', 's', 'Time one run of a resolution job took')
            ->record($seconds, ['outcome' => $kind]);
        Meter::counter('sonder.enrichment.resolution_run.settled', '{track}', 'Tracks a resolution run resolved or ruled out')
            ->add($settled, ['outcome' => $kind]);

        if ($idleSeconds !== null) {
            Meter::histogram('sonder.enrichment.resolution_run.idle', 's', 'Time a resolution job waited in the queue between two runs')
                ->record($idleSeconds);
        }

        Tracer::activeSpan()->setAttributes([
            'sonder.enrichment.run.outcome' => $outcome,
            'sonder.enrichment.run.settled' => $settled,
            'sonder.enrichment.run.remaining' => $remaining,
        ]);

        Log::info('Resolution run', [
            'outcome' => $outcome,
            'settled' => $settled,
            'remaining' => $remaining,
            'seconds' => round($seconds, 2),
            'idle_seconds' => $idleSeconds === null ? null : round($idleSeconds, 1),
        ]);
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

    public function chartEntries(string $chart, int $linked, int $unlinked): void
    {
        $counter = Meter::counter('sonder.enrichment.chart_entries', '{entry}', 'Chart entries snapshotted, by chart and whether the library knows them');
        $counter->add($linked, ['chart' => $chart, 'linked' => 'true']);
        $counter->add($unlinked, ['chart' => $chart, 'linked' => 'false']);
    }
}
