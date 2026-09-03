<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\PlaylistData;
use App\Data\PlaylistSummaryData;
use App\Exceptions\YouTubeMusicException;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Services\YouTubeMusic\Client;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class PlaylistController
{
    public function index(#[CurrentUser] User $user, Client $client): Response|RedirectResponse
    {
        $account = $user->youTubeMusicAccount()->first();

        if ($account === null) {
            return to_route('youtube-music-connection.create');
        }

        try {
            $playlists = $client->playlists($account->cookie);
        } catch (YouTubeMusicException) {
            return $this->expired();
        }

        return Inertia::render('playlist/Index', [
            'accountName' => $account->account_name,
            'playlists' => $playlists,
        ]);
    }

    public function show(string $playlistId, #[CurrentUser] User $user, Client $client): Response|RedirectResponse
    {
        $account = $user->youTubeMusicAccount()->first();

        if ($account === null) {
            return to_route('youtube-music-connection.create');
        }

        try {
            $summary = $this->summary($client, $account, $playlistId);
        } catch (YouTubeMusicException) {
            return $this->expired();
        }

        return Inertia::render('playlist/Show', [
            'playlistId' => $playlistId,
            'summary' => $summary,
            // Tracks arrive through a chain of continuation requests and can
            // take several seconds. The page shell renders from the summary,
            // already cached by the index, while this resolves. `rescue`
            // reports a failure to the exception handler and lets the page
            // render its retry state instead of erroring out entirely.
            'playlist' => Inertia::defer(
                fn (): PlaylistData => $client->playlist($account->cookie, $playlistId, $summary?->trackCount),
                rescue: true,
            ),
        ]);
    }

    private function summary(Client $client, YouTubeMusicAccount $account, string $playlistId): ?PlaylistSummaryData
    {
        foreach ($client->playlists($account->cookie) as $playlist) {
            if ($playlist->id === $playlistId) {
                return $playlist;
            }
        }

        return null;
    }

    private function expired(): RedirectResponse
    {
        return to_route('youtube-music-connection.create')->withErrors([
            'cookie' => 'The stored cookie no longer works. YouTube Music cookies expire after a few weeks; paste a fresh one.',
        ]);
    }
}
