<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\LibraryEnrichmentData;
use App\Data\RecentEnrichmentData;
use App\Enums\LibraryEnrichmentState;
use App\Enums\MetadataSource;
use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\Track;
use App\Models\YouTubeMusicAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class SummarizeLibraryEnrichment
{
    public const string QUEUE = 'enrichment';

    public const int RECENT = 5;

    public function __construct(private DescribeRecording $describe) {}

    public function handle(YouTubeMusicAccount $account): LibraryEnrichmentData
    {
        $videos = Track::query()
            ->whereNotNull('youtube_video_id')
            ->whereHas('playlist', fn (Builder $playlists): Builder => $playlists->where('youtube_music_account_id', $account->id))
            ->select('youtube_video_id');

        $total = Track::query()->fromSub($videos->clone()->distinct(), 'videos')->count();

        $resolutions = RecordingResolution::query()
            ->where('provider', Provider::YouTubeMusic)
            ->whereIn('external_id', $videos);

        $byStatus = $resolutions->clone()
            ->toBase()
            ->selectRaw('status, count(*) as tracks')
            ->groupBy('status')
            ->pluck('tracks', 'status')
            ->map(fn (mixed $tracks): int => is_numeric($tracks) ? (int) $tracks : 0);

        $resolved = $byStatus->get(ResolutionStatus::Resolved->value, 0);
        $notFound = $byStatus->get(ResolutionStatus::NotFound->value, 0);
        $failed = $byStatus->get(ResolutionStatus::Failed->value, 0);
        $pending = max(0, $total - $resolved - $notFound - $failed);

        $recordingIds = array_values($resolutions->clone()
            ->whereNotNull('recording_id')
            ->distinct()
            ->get(['recording_id'])
            ->map(fn (RecordingResolution $resolution): ?string => $resolution->recording_id)
            ->filter()
            ->all());

        $described = $this->described($recordingIds);
        $withGenre = DB::table('recording_tags')
            ->join('tags', 'tags.id', '=', 'recording_tags.tag_id')
            ->where('tags.is_genre', true)
            ->whereIn('recording_tags.recording_id', $recordingIds)
            ->distinct()
            ->count('recording_tags.recording_id');

        return new LibraryEnrichmentData(
            state: $this->state($byStatus->isEmpty(), $pending > 0 || $described < count($recordingIds), $failed > 0),
            total: $total,
            resolved: $resolved,
            notFound: $notFound,
            failed: $failed,
            pending: $pending,
            recordings: count($recordingIds),
            described: $described,
            withGenre: $withGenre,
            updatedAt: now()->toIso8601String(),
            recent: $this->recent($account, $recordingIds),
        );
    }

    /**
     * The library tracks something was learned about last: they were matched
     * to a recording, a source described them, or their genres and credits
     * were projected.
     *
     * @param  list<string>  $recordingIds
     * @return list<RecentEnrichmentData>
     */
    private function recent(YouTubeMusicAccount $account, array $recordingIds): array
    {
        $activity = $this->lastActivity($recordingIds);

        $recordings = Recording::query()
            ->whereKey(array_keys($activity))
            ->withCount('credits')
            ->get()
            ->keyBy('id');

        $videos = RecordingResolution::query()
            ->where('provider', Provider::YouTubeMusic)
            ->whereIn('recording_id', $recordings->keys()->all())
            ->get(['external_id', 'recording_id']);

        $tracks = Track::query()
            ->whereIn('youtube_video_id', $videos->pluck('external_id')->all())
            ->whereHas('playlist', fn (Builder $playlists): Builder => $playlists->where('youtube_music_account_id', $account->id))
            ->get()
            ->keyBy('youtube_video_id');

        $genres = $this->genres(array_values($recordings->map(fn (Recording $recording): string => $recording->id)->all()));
        $recent = [];

        foreach ($activity as $recordingId => $at) {
            $recording = $recordings->get($recordingId);

            if (! $recording instanceof Recording) {
                continue;
            }

            $track = $videos
                ->where('recording_id', $recordingId)
                ->map(fn (RecordingResolution $resolution): ?Track => $tracks->get($resolution->external_id))
                ->first(fn (?Track $track): bool => $track !== null);

            if (! $track instanceof Track) {
                continue;
            }

            $recent[] = new RecentEnrichmentData(
                videoId: (string) $track->youtube_video_id,
                title: $track->title,
                artists: $track->artists,
                thumbnailUrl: $track->thumbnail_url,
                genres: $genres[$recordingId] ?? [],
                creditCount: $recording->credits_count ?? 0,
                year: $recording->release_date?->year,
                enrichedAt: $at->toIso8601String(),
            );
        }

        return $recent;
    }

    /**
     * The latest of each recording's identification, description and
     * projection, newest first.
     *
     * @param  list<string>  $recordingIds
     * @return array<string, CarbonImmutable>
     */
    private function lastActivity(array $recordingIds): array
    {
        $activity = [];
        $note = function (mixed $recordingId, mixed $at) use (&$activity): void {
            if (! is_string($recordingId) || (! is_string($at) && ! $at instanceof CarbonInterface)) {
                return;
            }

            $at = CarbonImmutable::parse($at);

            if (! isset($activity[$recordingId]) || $at->greaterThan($activity[$recordingId])) {
                $activity[$recordingId] = $at;
            }
        };

        Enrichment::query()
            ->where('subject_type', Enrichment::RECORDING)
            ->whereIn('subject_key', $recordingIds)
            ->whereNotNull('fetched_at')
            ->toBase()
            ->selectRaw('subject_key, max(fetched_at) as described_at')
            ->groupBy('subject_key')
            ->orderByDesc('described_at')
            ->limit(self::RECENT)
            ->get()
            ->each(fn (object $row) => $note($row->subject_key ?? null, $row->described_at ?? null));

        RecordingResolution::query()
            ->where('provider', Provider::YouTubeMusic)
            ->whereIn('recording_id', $recordingIds)
            ->whereNotNull('resolved_at')
            ->toBase()
            ->selectRaw('recording_id, max(resolved_at) as resolved_at')
            ->groupBy('recording_id')
            ->orderByDesc('resolved_at')
            ->limit(self::RECENT)
            ->get()
            ->each(fn (object $row) => $note($row->recording_id ?? null, $row->resolved_at ?? null));

        Recording::query()
            ->whereKey($recordingIds)
            ->whereNotNull('projected_at')
            ->latest('projected_at')
            ->limit(self::RECENT)
            ->get(['id', 'projected_at'])
            ->each(fn (Recording $recording) => $note($recording->id, $recording->projected_at));

        uasort($activity, fn (CarbonImmutable $a, CarbonImmutable $b): int => $b <=> $a);

        return array_slice($activity, 0, self::RECENT, preserve_keys: true);
    }

    /**
     * Each recording's three strongest genres, weights summed across sources.
     *
     * @param  list<string>  $recordingIds
     * @return array<string, list<string>>
     */
    private function genres(array $recordingIds): array
    {
        $genres = [];

        DB::table('recording_tags')
            ->join('tags', 'tags.id', '=', 'recording_tags.tag_id')
            ->where('tags.is_genre', true)
            ->whereIn('recording_tags.recording_id', $recordingIds)
            ->selectRaw('recording_tags.recording_id, tags.name, sum(recording_tags.weight) as weight')
            ->groupBy('recording_tags.recording_id', 'tags.name')
            ->orderByDesc('weight')
            ->orderBy('tags.name')
            ->get()
            ->each(function (object $row) use (&$genres): void {
                $recordingId = $row->recording_id ?? null;
                $name = $row->name ?? null;

                if (is_string($recordingId) && is_string($name) && count($genres[$recordingId] ?? []) < 3) {
                    $genres[$recordingId][] = $name;
                }
            });

        return $genres;
    }

    /**
     * Recordings every source able to describe them has answered about,
     * whatever the answer: credits.fm needs an ISRC, MusicBrainz an MBID.
     *
     * @param  list<string>  $recordingIds
     */
    private function described(array $recordingIds): int
    {
        $answered = fn (MetadataSource $source): Closure => fn (QueryBuilder $enrichments): QueryBuilder => $enrichments->select(DB::raw(1))
            ->from('enrichments')
            ->where('subject_type', Enrichment::RECORDING)
            ->where('source', $source->value)
            ->whereRaw('enrichments.subject_key = recordings.id::text');
        $sources = $this->describe->sources();

        return Recording::query()
            ->whereIn('id', $recordingIds)
            ->when(in_array(MetadataSource::CreditsFm, $sources, true), fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query->whereNull('isrc')->orWhereExists($answered(MetadataSource::CreditsFm))))
            ->when(in_array(MetadataSource::MusicBrainz, $sources, true), fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query->whereNull('mbid')->orWhereExists($answered(MetadataSource::MusicBrainz))))
            ->when(in_array(MetadataSource::LastFm, $sources, true), fn (Builder $query): Builder => $query->whereExists($answered(MetadataSource::LastFm)))
            ->count();
    }

    private function state(bool $neverQueued, bool $working, bool $failed): LibraryEnrichmentState
    {
        return match (true) {
            $neverQueued => LibraryEnrichmentState::Idle,
            $working && Queue::isPaused(config()->string('queue.default'), self::QUEUE) => LibraryEnrichmentState::Paused,
            $working => LibraryEnrichmentState::Running,
            $failed => LibraryEnrichmentState::Attention,
            default => LibraryEnrichmentState::Done,
        };
    }
}
