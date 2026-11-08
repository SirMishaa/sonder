<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ArtistInLibraryData;
use App\Models\Listen;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Illuminate\Database\Eloquent\Builder;

final readonly class DescribeArtistInLibrary
{
    /**
     * What the user's library and listens hold of an artist. A row belongs
     * to the artist when its credits equal the name or start with it
     * followed by a comma, case-insensitively.
     */
    public function handle(User $user, string $artistName): ArtistInLibraryData
    {
        $name = mb_strtolower(mb_trim($artistName));
        $escaped = addcslashes($name, '\\%_');

        $matches = fn (Builder $query): Builder => $query->where(
            fn (Builder $credits): Builder => $credits
                ->whereRaw('lower(artists) = ?', [$name])
                ->orWhereRaw("lower(artists) like ? escape '\\'", [$escaped.',%']),
        );

        $tracks = Track::query()
            ->whereIn('playlist_id', Playlist::query()->select('id')->whereIn(
                'youtube_music_account_id',
                YouTubeMusicAccount::query()->select('id')->where('user_id', $user->id),
            ))
            ->tap($matches)
            ->get(['youtube_video_id', 'title', 'album']);

        $listens = Listen::query()
            ->where('user_id', $user->id)
            ->tap($matches)
            ->get(['title', 'listened_seconds', 'started_at']);

        $perTitle = [];

        foreach ($listens as $listen) {
            $perTitle[$listen->title] = ($perTitle[$listen->title] ?? 0) + 1;
        }

        arsort($perTitle);

        $topTracks = [];

        foreach (array_slice($perTitle, 0, 4, true) as $title => $count) {
            $topTracks[] = ['title' => (string) $title, 'listens' => $count];
        }

        return new ArtistInLibraryData(
            trackCount: $tracks->unique(fn (Track $track): string => $track->youtube_video_id ?? $track->title)->count(),
            albumCount: $tracks->pluck('album')->filter()->unique()->count(),
            listenCount: $listens->count(),
            listenedMinutes: intdiv((int) $listens->sum('listened_seconds'), 60),
            firstListenedAt: $listens->min('started_at'),
            lastListenedAt: $listens->max('started_at'),
            topTracks: $topTracks,
        );
    }
}
