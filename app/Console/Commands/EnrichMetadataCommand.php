<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\QueueTrackResolution;
use App\Actions\RecordEnrichmentCoverage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metadata:enrich {--refresh : Resolve again the tracks already resolved}')]
#[Description('Queue the library for metadata enrichment (credits.fm, MusicBrainz)')]
final class EnrichMetadataCommand extends Command
{
    public function handle(QueueTrackResolution $queue, RecordEnrichmentCoverage $coverage): int
    {
        $queued = $queue->handle(refresh: (bool) $this->option('refresh'));
        $coverage->handle();

        $this->components->info("Queued {$queued} tracks for enrichment.");

        return self::SUCCESS;
    }
}
