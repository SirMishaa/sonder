<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecordEnrichmentCoverage;
use App\Actions\ResolveRecordings;
use App\Enums\Provider;
use App\Jobs\EnrichContributor;
use App\Jobs\EnrichRecording;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Enrichment;
use App\Models\RecordingResolution;
use App\Models\Track;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('metadata:retry-due')]
#[Description('Fetch again the metadata that failed, was missing or went stale')]
final class RetryDueMetadataCommand extends Command
{
    public function handle(RecordEnrichmentCoverage $coverage): int
    {
        $retried = 0;

        Enrichment::query()
            ->whereIn('subject_type', [Enrichment::RECORDING, Enrichment::CONTRIBUTOR])
            ->where('next_attempt_at', '<=', now())
            ->lazyById()
            ->each(function (Enrichment $enrichment) use (&$retried): void {
                $retried++;
                $enrichment->update(['next_attempt_at' => now()->addDay()]);

                if ($enrichment->subject_type === Enrichment::RECORDING) {
                    EnrichRecording::dispatch($enrichment->subject_key, $enrichment->source);
                } else {
                    EnrichContributor::dispatch($enrichment->subject_key, $enrichment->source);
                }
            });

        RecordingResolution::query()
            ->where('provider', Provider::YouTubeMusic)
            ->where('next_attempt_at', '<=', now())
            ->get()
            ->chunk(ResolveRecordings::MAX_TRACKS)
            ->each(
                /** @param Collection<int, RecordingResolution> $chunk */
                function (Collection $chunk) use (&$retried): void {
                    $tracks = Track::query()
                        ->whereIn('youtube_video_id', $chunk->pluck('external_id'))
                        ->get(['youtube_video_id', 'title', 'artists', 'duration_seconds'])
                        ->unique('youtube_video_id')
                        ->values();

                    RecordingResolution::query()->whereKey($chunk->pluck('id')->all())->update(['next_attempt_at' => now()->addDay()]);
                    $retried += $tracks->count();

                    if ($tracks->isNotEmpty()) {
                        ResolveLibraryTracks::dispatch(array_values($tracks->map(fn (Track $track): array => [
                            'provider' => Provider::YouTubeMusic->value,
                            'externalId' => (string) $track->youtube_video_id,
                            'title' => $track->title,
                            'artists' => $track->artists,
                            'durationSeconds' => $track->duration_seconds,
                        ])->all()));
                    }
                });

        $coverage->handle();
        $this->components->info("Queued {$retried} metadata lookups again.");

        return self::SUCCESS;
    }
}
