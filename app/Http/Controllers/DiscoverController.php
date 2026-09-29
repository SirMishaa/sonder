<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CheckLibraryFreshness;
use App\Actions\SampleLibraryTracks;
use App\Actions\StartYouTubeMusicSync;
use App\Actions\SummarizeLibrary;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final readonly class DiscoverController
{
    private const int FRESH_FINDS = 6;

    public function index(
        #[CurrentUser] User $user,
        StartYouTubeMusicSync $startSync,
        CheckLibraryFreshness $checkFreshness,
        SummarizeLibrary $summarize,
        SampleLibraryTracks $sample,
    ): Response|RedirectResponse {
        $account = $user->youTubeMusicAccount()->first();

        if ($account === null) {
            return to_route('youtube-music-connection.create');
        }

        if ($account->playlists()->doesntExist()) {
            return to_route('youtube-music-connection.sync', $startSync->handle($account));
        }

        $checkFreshness->handle($account);

        return Inertia::render('discover/Index', [
            'stats' => Inertia::defer(fn () => $summarize->handle($account)),
            'freshFinds' => Inertia::defer(fn () => $sample->handle($account, self::FRESH_FINDS)),
        ]);
    }
}
