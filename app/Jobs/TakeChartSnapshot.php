<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SnapshotChart;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Takes today's snapshot of one chart.
 */
final class TakeChartSnapshot implements ShouldQueue
{
    use Queueable;

    /**
     * Releases for a rate limit are not exceptions: they may repeat until
     * `retryUntil()`. Real failures get three tries.
     */
    public int $maxExceptions = 3;

    public function __construct(public readonly string $chart)
    {
        $this->onQueue('enrichment');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(hours: 12);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(SnapshotChart $snapshot): void
    {
        try {
            $snapshot->handle($this->chart);
        } catch (MetadataSourceRateLimited $exception) {
            $this->release($exception->retryAfter);
        }
    }
}
