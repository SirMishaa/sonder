<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DescribeContributor;
use App\Actions\DescribeRecording;
use App\Actions\RecordEnrichmentCoverage;
use App\Actions\ResolveRecordings;
use App\Enums\CreditType;
use App\Enums\MetadataSource;
use App\Enums\Provider;
use App\Jobs\EnrichContributor;
use App\Jobs\EnrichRecording;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\Track;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('metadata:retry-due')]
#[Description('Fetch again the metadata that failed, was missing or went stale, and what a source never described')]
final class RetryDueMetadataCommand extends Command
{
    public function handle(RecordEnrichmentCoverage $coverage, DescribeRecording $describeRecording, DescribeContributor $describeContributor): int
    {
        $retried = 0;
        $dispatched = [];

        Enrichment::query()
            ->whereIn('subject_type', [Enrichment::RECORDING, Enrichment::CONTRIBUTOR])
            ->where('next_attempt_at', '<=', now())
            ->lazyById()
            ->each(function (Enrichment $enrichment) use (&$retried, &$dispatched, $describeRecording): void {
                if ($enrichment->source === MetadataSource::LastFm && ! in_array(MetadataSource::LastFm, $describeRecording->sources(), true)) {
                    return;
                }

                $enrichment->update(['next_attempt_at' => now()->addDay()]);
                $key = "{$enrichment->subject_type}:{$enrichment->subject_key}:{$enrichment->source->value}";

                if (isset($dispatched[$key])) {
                    return;
                }

                $dispatched[$key] = true;
                $retried++;

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

        foreach ($describeRecording->sources() as $source) {
            $this->undescribedRecordings($source)->lazyById()->each(function (Recording $recording) use ($source, &$retried): void {
                $retried++;
                EnrichRecording::dispatch($recording->id, $source);
            });
        }

        Contributor::query()
            ->whereExists(fn (QueryBuilder $credits): QueryBuilder => $credits->select(DB::raw(1))
                ->from('recording_contributors')
                ->whereColumn('recording_contributors.contributor_id', 'contributors.id')
                ->where('credit_type', CreditType::Artist->value))
            ->lazyById()
            ->each(function (Contributor $contributor) use ($describeContributor, &$retried): void {
                foreach ($describeContributor->undescribedSources($contributor) as $source) {
                    $retried++;
                    EnrichContributor::dispatch($contributor->id, $source);
                }
            });

        $coverage->handle();
        $this->components->info("Queued {$retried} metadata lookups again.");

        return self::SUCCESS;
    }

    /**
     * The recordings this source can identify and never answered about.
     *
     * @return Builder<Recording>
     */
    private function undescribedRecordings(MetadataSource $source): Builder
    {
        return Recording::query()
            ->when($source === MetadataSource::CreditsFm, fn (Builder $query): Builder => $query->whereNotNull('isrc'))
            ->when($source === MetadataSource::MusicBrainz, fn (Builder $query): Builder => $query->whereNotNull('mbid'))
            ->whereNotExists(fn (QueryBuilder $enrichments): QueryBuilder => $enrichments->select(DB::raw(1))
                ->from('enrichments')
                ->where('subject_type', Enrichment::RECORDING)
                ->where('source', $source->value)
                ->whereRaw('enrichments.subject_key = recordings.id::text'));
    }
}
