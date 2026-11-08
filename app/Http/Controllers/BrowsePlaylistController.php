<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildPublicPlaylistPage;
use App\Exceptions\Providers\ProviderException;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class BrowsePlaylistController
{
    public function show(string $playlistId, #[CurrentUser] User $user, BuildPublicPlaylistPage $build): Response|RedirectResponse
    {
        $inLibrary = $user->youTubeMusicAccount()->first()?->playlists()
            ->where('youtube_playlist_id', $playlistId)
            ->exists();

        if ($inLibrary === true) {
            return to_route('playlist.show', $playlistId);
        }

        try {
            return Inertia::render('browse-playlist/Show', ['page' => $build->handle($user, $playlistId)]);
        } catch (ProviderException $exception) {
            return Inertia::render('browse-playlist/Show', ['page' => null, 'catalogError' => $exception->userMessage()]);
        }
    }
}
