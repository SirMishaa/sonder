<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Jobs\ResolveLibraryTracks;
use App\Models\RecordingResolution;
use App\Models\Track;
use App\Models\YouTubeMusicAccount;
use Illuminate\Database\Eloquent\Builder;

final readonly class QueueTrackResolution
{
    /**
     * Queues the library's YouTube Music videos for resolution, one job per
     * ResolveRecordings::MAX_TRACKS distinct videos, marking each one
     * pending. Without $refresh, only videos never queued before.
     */
    public function handle(?YouTubeMusicAccount $account = null, bool $refresh = false): int
    {
        $tracks = Track::query()
            ->whereNotNull('youtube_video_id')
            ->when($account, fn (Builder $query, YouTubeMusicAccount $account): Builder => $query->whereHas(
                'playlist',
                fn (Builder $playlists): Builder => $playlists->where('youtube_music_account_id', $account->id),
            ))
            ->when(! $refresh, fn (Builder $query): Builder => $query->whereNotIn(
                'youtube_video_id',
                RecordingResolution::query()->where('provider', Provider::YouTubeMusic)->select('external_id'),
            ))
            ->orderBy('youtube_video_id')
            ->get(['youtube_video_id', 'title', 'artists', 'duration_seconds'])
            ->unique('youtube_video_id')
            ->values();

        foreach ($tracks->chunk(ResolveRecordings::MAX_TRACKS) as $chunk) {
            $batch = [];

            foreach ($chunk as $track) {
                $videoId = (string) $track->youtube_video_id;

                RecordingResolution::query()->createOrFirst(
                    ['provider' => Provider::YouTubeMusic, 'external_id' => $videoId],
                    [
                        'status' => ResolutionStatus::Pending,
                        'query_title' => $track->title,
                        'query_artist' => $track->artists,
                        'next_attempt_at' => now()->addDay(),
                    ],
                );

                $batch[] = [
                    'provider' => Provider::YouTubeMusic->value,
                    'externalId' => $videoId,
                    'title' => $track->title,
                    'artists' => $track->artists,
                    'durationSeconds' => $track->duration_seconds,
                ];
            }

            ResolveLibraryTracks::dispatch($batch);
        }

        return $tracks->count();
    }
}
