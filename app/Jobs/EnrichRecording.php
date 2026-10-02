<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\AnnounceEnrichmentProgress;
use App\Actions\DescribeRecording;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Models\Enrichment;
use App\Models\Recording;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks one metadata source about one recording, then projects the result.
 */
final class EnrichRecording implements ShouldQueue
{
    use Queueable;

    /**
     * Releases for a rate limit are not exceptions: they may repeat until
     * `retryUntil()`. Real failures get three tries.
     */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $recordingId,
        public readonly MetadataSource $source,
    ) {
        $this->onQueue('enrichment');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(days: 1);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(): void
    {
        $recording = Recording::query()->find($this->recordingId);

        if ($recording === null) {
            Enrichment::query()->where('subject_type', Enrichment::RECORDING)->where('subject_key', $this->recordingId)->delete();

            return;
        }

        try {
            $status = resolve(DescribeRecording::class)->handle($recording, $this->source);
        } catch (MetadataSourceRateLimited $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        if ($status instanceof EnrichmentStatus) {
            ProjectRecordingMetadata::dispatch($recording->id);
        }

        resolve(AnnounceEnrichmentProgress::class)->forRecording($recording->id);
    }

    /**
     * Marks as failed what this source still owes: answers stored before the
     * failure stay as they are.
     */
    public function failed(Throwable $exception): void
    {
        foreach (DescribeRecording::endpoints($this->source) as $endpoint) {
            if (Enrichment::isDue(Enrichment::RECORDING, $this->recordingId, $this->source, $endpoint)) {
                Enrichment::store(Enrichment::RECORDING, $this->recordingId, $this->source, $endpoint, EnrichmentStatus::Failed, error: $exception->getMessage());
            }
        }

        resolve(AnnounceEnrichmentProgress::class)->forRecording($this->recordingId);
    }
}
