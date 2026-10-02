<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\AnnounceEnrichmentProgress;
use App\Actions\DescribeContributor;
use App\Actions\ProjectEnrichment;
use App\Models\Recording;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Rebuilds one recording's structured metadata from its stored payloads.
 */
final class ProjectRecordingMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $recordingId)
    {
        $this->onQueue('enrichment');
    }

    /**
     * Two sources may finish together; their projections take turns.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->recordingId))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(): void
    {
        $recording = Recording::query()->find($this->recordingId);

        if ($recording === null) {
            return;
        }

        $describe = resolve(DescribeContributor::class);

        foreach (resolve(ProjectEnrichment::class)->handle($recording) as $contributor) {
            foreach ($describe->undescribedSources($contributor) as $source) {
                EnrichContributor::dispatch($contributor->id, $source);
            }
        }

        resolve(AnnounceEnrichmentProgress::class)->forRecording($recording->id);
    }
}
