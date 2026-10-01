<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\DescribeRecording;
use App\Actions\ResolveRecordings;
use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Models\RecordingResolution;
use App\Services\Metadata\Data\TrackToResolve;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Resolves up to ResolveRecordings::MAX_TRACKS library tracks, then starts
 * describing what they resolved to. MusicBrainz allows one call a second,
 * so the job is released many times; each run skips what earlier runs
 * already settled.
 */
final class ResolveLibraryTracks implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public readonly int $queuedAt;

    /**
     * @param  list<array{provider: string, externalId: string, title: string, artists: string, durationSeconds: int|null}>  $tracks
     * @param  int  $budgetSeconds  how long one run may resolve before it hands the worker back
     */
    public function __construct(public readonly array $tracks, public readonly int $budgetSeconds = 30)
    {
        $this->queuedAt = now()->getTimestamp();
        $this->onQueue('enrichment');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(hours: 2);
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
        $pending = $this->unsettled();

        if ($pending !== []) {
            try {
                resolve(ResolveRecordings::class)->handle($pending, until: now()->addSeconds($this->budgetSeconds));
            } catch (MetadataSourceRateLimited $exception) {
                $this->release($exception->retryAfter);

                return;
            }

            if ($this->unsettled() !== []) {
                $this->release(1);

                return;
            }
        }

        $this->describeResolved();
    }

    public function failed(Throwable $exception): void
    {
        $this->describeResolved();

        foreach ($this->unsettled() as $track) {
            RecordingResolution::query()->updateOrCreate(
                ['provider' => $track->provider, 'external_id' => $track->externalId],
                [
                    'status' => ResolutionStatus::Failed,
                    'query_title' => $track->title,
                    'query_artist' => $track->artists,
                    'next_attempt_at' => now()->addDay(),
                ],
            );
        }
    }

    /**
     * Starts describing every recording the runs of this job resolved, once
     * they are all done (earlier runs may have been released halfway).
     */
    private function describeResolved(): void
    {
        $recordingIds = RecordingResolution::query()
            ->whereIn('external_id', array_column($this->tracks, 'externalId'))
            ->where('status', ResolutionStatus::Resolved)
            ->where('updated_at', '>=', now()->setTimestamp($this->queuedAt))
            ->whereNotNull('recording_id')
            ->get(['recording_id'])
            ->map(fn (RecordingResolution $resolution): ?string => $resolution->recording_id)
            ->filter()
            ->unique();

        foreach ($recordingIds as $recordingId) {
            foreach (DescribeRecording::SOURCES as $source) {
                EnrichRecording::dispatch($recordingId, $source);
            }
        }
    }

    /**
     * The tracks no run of this job has resolved or ruled out yet.
     *
     * @return list<TrackToResolve>
     */
    private function unsettled(): array
    {
        $tracks = array_map(fn (array $track): TrackToResolve => new TrackToResolve(
            Provider::from($track['provider']),
            $track['externalId'],
            $track['title'],
            $track['artists'],
            $track['durationSeconds'],
        ), $this->tracks);

        $settled = RecordingResolution::query()
            ->whereIn('external_id', array_column($this->tracks, 'externalId'))
            ->whereIn('status', [ResolutionStatus::Resolved, ResolutionStatus::NotFound])
            ->where('updated_at', '>=', now()->setTimestamp($this->queuedAt))
            ->get(['provider', 'external_id'])
            ->map(fn (RecordingResolution $resolution): string => "{$resolution->provider->value}:{$resolution->external_id}")
            ->all();

        return array_values(array_filter($tracks, fn (TrackToResolve $track): bool => ! in_array($track->key(), $settled, true)));
    }
}
