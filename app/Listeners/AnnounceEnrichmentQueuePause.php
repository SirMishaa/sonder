<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\AnnounceEnrichmentProgress;
use App\Actions\SummarizeLibraryEnrichment;
use Illuminate\Queue\Events\QueuePaused;
use Illuminate\Queue\Events\QueueResumed;

/**
 * Pausing or resuming the enrichment queue changes every library's state.
 */
final readonly class AnnounceEnrichmentQueuePause
{
    public function __construct(private AnnounceEnrichmentProgress $announce) {}

    public function handle(QueuePaused|QueueResumed $event): void
    {
        if ($event->queue === SummarizeLibraryEnrichment::QUEUE) {
            $this->announce->forEveryLibrary();
        }
    }
}
