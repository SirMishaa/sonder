<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Services\Music\Data\RemoteTrack;
use App\Support\MusicText;
use Illuminate\Support\Facades\DB;

final readonly class MatchLibraryTracks
{
    /**
     * Which catalog tracks the user's library holds: by video id, else by
     * normalized title and first artist.
     *
     * @param  list<RemoteTrack>  $tracks
     * @return array<int, bool> index in $tracks => in the library
     */
    public function handle(User $user, array $tracks): array
    {
        if ($tracks === []) {
            return [];
        }

        $videoIds = [];
        $titles = [];

        foreach ($tracks as $track) {
            if ($track->ref !== null) {
                $videoIds[] = $track->ref->externalId;
            }

            $titles[] = mb_strtolower($track->title);
        }

        $ids = [];
        $keys = [];

        Track::query()
            ->whereIn('playlist_id', Playlist::query()->select('id')->whereIn(
                'youtube_music_account_id',
                YouTubeMusicAccount::query()->select('id')->where('user_id', $user->id),
            ))
            ->where(function ($query) use ($videoIds, $titles): void {
                $query->whereIn('youtube_video_id', $videoIds)
                    ->orWhereIn(DB::raw('lower(title)'), $titles);
            })
            ->get(['youtube_video_id', 'title', 'artists'])
            ->each(function (Track $track) use (&$ids, &$keys): void {
                if ($track->youtube_video_id !== null) {
                    $ids[$track->youtube_video_id] = true;
                }

                $keys[MusicText::matchTitle($track->title).'|'.MusicText::matchArtist($track->artists)] = true;
            });

        $matches = [];

        foreach ($tracks as $index => $track) {
            $key = MusicText::matchTitle($track->title).'|'.MusicText::matchArtist($track->artistNames());

            $matches[$index] = ($track->ref !== null && isset($ids[$track->ref->externalId]))
                || ($track->artists !== [] && isset($keys[$key]));
        }

        return $matches;
    }
}
