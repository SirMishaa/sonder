<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RefreshPlaylist;
use App\Exceptions\YouTubeMusicException;
use App\Exceptions\YouTubeMusicRateLimitedException;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final readonly class PlaylistRefreshController
{
    public function store(string $playlistId, #[CurrentUser] User $user, RefreshPlaylist $refresh): RedirectResponse
    {
        $playlist = $user->youTubeMusicAccount()->first()
            ?->playlists()
            ->where('youtube_playlist_id', $playlistId)
            ->first();

        abort_if($playlist === null, 404);

        if ($playlist->removed_at !== null) {
            return back()->withErrors([
                'refresh' => __('This playlist is no longer in your YouTube Music library.'),
            ]);
        }

        try {
            $refresh->handle($playlist);
        } catch (YouTubeMusicRateLimitedException $exception) {
            return back()->withErrors(['refresh' => __('Sonder is pacing its calls to YouTube Music. Try again in :seconds seconds.', ['seconds' => $exception->retryAfter])]);
        } catch (YouTubeMusicException $exception) {
            report($exception);

            return back()->withErrors([
                'refresh' => __('YouTube Music refused the request. The stored cookie may have expired.'),
            ]);
        }

        return back();
    }
}
