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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class SummarizeLibraryEnrichment
{
    public const string QUEUE = 'enrichment';

    public const int RECENT = 5;

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
     * The library tracks whose recordings were described last.
     *
     * @param  list<string>  $recordingIds
     * @return list<RecentEnrichmentData>
     */
    private function recent(YouTubeMusicAccount $account, array $recordingIds): array
    {
        $describedAt = Enrichment::query()
            ->where('subject_type', Enrichment::RECORDING)
            ->whereIn('subject_key', $recordingIds)
            ->whereNotNull('fetched_at')
            ->toBase()
            ->selectRaw('subject_key, max(fetched_at) as described_at')
            ->groupBy('subject_key')
            ->orderByDesc('described_at')
            ->limit(self::RECENT)
            ->pluck('described_at', 'subject_key');

        $recordings = Recording::query()
            ->whereKey($describedAt->keys()->all())
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

        foreach ($describedAt as $recordingId => $at) {
            $recording = $recordings->get($recordingId);
            $track = $videos
                ->where('recording_id', $recordingId)
                ->map(fn (RecordingResolution $resolution): ?Track => $tracks->get($resolution->external_id))
                ->first(fn (?Track $track): bool => $track !== null);

            if (! $recording instanceof Recording || ! $track instanceof Track) {
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
                enrichedAt: Date::parse(is_string($at) ? $at : 'now')->toIso8601String(),
            );
        }

        return $recent;
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
     * Recordings every description source has answered about, whatever the answer.
     *
     * @param  list<string>  $recordingIds
     */
    private function described(array $recordingIds): int
    {
        $sources = array_map(fn (MetadataSource $source): string => $source->value, DescribeRecording::SOURCES);

        $answered = Enrichment::query()
            ->where('subject_type', Enrichment::RECORDING)
            ->whereIn('subject_key', $recordingIds)
            ->whereIn('source', $sources)
            ->select('subject_key')
            ->groupBy('subject_key')
            ->havingRaw('count(distinct source) = ?', [count($sources)]);

        return DB::query()->fromSub($answered, 'answered')->count();
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
