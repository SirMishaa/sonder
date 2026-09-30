<?php

declare(strict_types=1);

namespace App\Services\OpenTelemetry;

use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\PushMetricExporterInterface;

/**
 * Grafana Cloud (Mimir) rejects the whole OTLP metrics batch when any metric
 * has zero data points, which happens on every export because the SDK's own
 * self-diagnostics counters (otel.sdk.processor.*, otel.sdk.exporter.*) start
 * empty. This decorator strips those before handing the batch to the real
 * exporter, so our own instruments still get exported.
 *
 * Wired in by patches/keepsuit-laravel-opentelemetry-drop-empty-metrics.patch.
 */
final readonly class DropEmptyMetricsExporter implements AggregationTemporalitySelectorInterface, PushMetricExporterInterface
{
    public function __construct(private PushMetricExporterInterface $inner) {}

    public function temporality(MetricMetadataInterface $metric): Temporality|string|null
    {
        return $this->inner instanceof AggregationTemporalitySelectorInterface
            ? $this->inner->temporality($metric)
            : null;
    }

    /**
     * @param  iterable<int, Metric>  $batch
     */
    public function export(iterable $batch): bool
    {
        $filtered = [];

        foreach ($batch as $metric) {
            if ($metric->data->dataPointCount() === 0) {
                continue;
            }

            $filtered[] = $metric;
        }

        return $filtered === [] || $this->inner->export($filtered);
    }

    public function shutdown(): bool
    {
        return $this->inner->shutdown();
    }

    public function forceFlush(): bool
    {
        return $this->inner->forceFlush();
    }
}
