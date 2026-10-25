<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\VideoKnowledgeData;
use App\Models\ExtensionCommand;
use App\Models\Listen;
use App\Models\Track;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Tells the extension what Sonder knows about YouTube videos: whether they
 * are in the user's library and in which playlists, how often they were
 * played, and whether a mix was already sent. Three queries at most,
 * whatever the number of videos.
 */
final readonly class DescribeYouTubeVideos
{
    /**
     * @param  list<string>  $videoIds
     * @return array{videos: array<string, VideoKnowledgeData>, list: array{sentBefore: bool, lastSentAt: string|null}|null}
     */
    public function handle(User $user, array $videoIds, ?string $listId): array
    {
        /** @var array<string, list<string>> $playlists */
        $playlists = [];

        $rows = Track::query()
            ->join('playlists', 'playlists.id', '=', 'tracks.playlist_id')
            ->join('youtube_music_accounts', 'youtube_music_accounts.id', '=', 'playlists.youtube_music_account_id')
            ->where('youtube_music_accounts.user_id', $user->id)
            ->whereNull('playlists.removed_at')
            ->whereIn('tracks.youtube_video_id', $videoIds)
            ->distinct()
            ->orderBy('playlists.title')
            ->get(['tracks.youtube_video_id', 'playlists.title as playlist_title']);

        foreach ($rows as $row) {
            $videoId = $row->youtube_video_id;
            $title = $row->getAttribute('playlist_title');

            if ($videoId !== null && is_string($title) && ! in_array($title, $playlists[$videoId] ?? [], true)) {
                $playlists[$videoId][] = $title;
            }
        }

        $listens = Listen::query()
            ->where('user_id', $user->id)
            ->whereIn('youtube_video_id', $videoIds)
            ->groupBy('youtube_video_id')
            ->selectRaw('youtube_video_id, count(*) as listen_count, max(started_at) as last_started_at')
            ->get()
            ->keyBy('youtube_video_id');

        $videos = [];

        foreach ($videoIds as $videoId) {
            $titles = $playlists[$videoId] ?? [];
            $listen = $listens->get($videoId);
            $lastStartedAt = $listen?->getAttribute('last_started_at');

            $videos[$videoId] = new VideoKnowledgeData(
                inLibrary: $titles !== [],
                playlists: $titles,
                listenCount: (int) ($listen?->getAttribute('listen_count') ?? 0),
                lastListenedAt: is_string($lastStartedAt) ? CarbonImmutable::parse($lastStartedAt)->toIso8601String() : null,
            );
        }

        return ['videos' => $videos, 'list' => $listId === null ? null : $this->list($user, $listId)];
    }

    /**
     * @return array{sentBefore: bool, lastSentAt: string|null}
     */
    private function list(User $user, string $listId): array
    {
        $lastSent = ExtensionCommand::query()
            ->where('user_id', $user->id)
            ->where('list_id', $listId)
            ->latest()
            ->first();

        return [
            'sentBefore' => $lastSent !== null,
            'lastSentAt' => $lastSent?->created_at->toIso8601String(),
        ];
    }
}
