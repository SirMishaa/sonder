<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LibraryEnrichmentState;
use App\Enums\Provider;
use App\Events\LibraryEnrichmentUpdated;
use App\Models\RecordingResolution;
use App\Models\YouTubeMusicAccount;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Broadcasts the enrichment summary to the libraries a piece of work touched.
 * Hundreds of jobs run back to back, so running work is announced at most
 * once per QUIET_SECONDS per library; the moment it settles always is.
 * Enrichment never depends on the websocket: a failed broadcast is reported.
 */
final readonly class AnnounceEnrichmentProgress
{
    public const int QUIET_SECONDS = 2;

    public function __construct(
        private SummarizeLibraryEnrichment $summarize,
        private Cache $cache,
    ) {}

    /**
     * @param  list<string>  $videoIds
     */
    public function forVideos(array $videoIds): void
    {
        YouTubeMusicAccount::query()
            ->whereHas('playlists.tracks', fn (Builder $tracks): Builder => $tracks->whereIn('youtube_video_id', $videoIds))
            ->get()
            ->each(fn (YouTubeMusicAccount $account) => $this->announce($account));
    }

    public function forRecording(string $recordingId): void
    {
        $this->forVideos(array_values(RecordingResolution::query()
            ->where('provider', Provider::YouTubeMusic)
            ->where('recording_id', $recordingId)
            ->get(['external_id'])
            ->map(fn (RecordingResolution $resolution): string => $resolution->external_id)
            ->all()));
    }

    /**
     * Every library with enrichment work behind or ahead of it.
     */
    public function forEveryLibrary(): void
    {
        YouTubeMusicAccount::query()->each(fn (YouTubeMusicAccount $account) => $this->announce($account));
    }

    private function announce(YouTubeMusicAccount $account): void
    {
        $summary = $this->summarize->handle($account);

        if ($summary->state === LibraryEnrichmentState::Running
            && ! $this->cache->add("enrichment-progress:{$account->id}", true, self::QUIET_SECONDS)) {
            return;
        }

        try {
            broadcast(new LibraryEnrichmentUpdated($account->user_id, $summary));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
