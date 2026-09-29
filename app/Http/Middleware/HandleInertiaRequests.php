<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\LibraryData;
use App\Data\LibraryPlaylistData;
use App\Data\YouTubeMusicSyncData;
use App\Enums\YouTubeMusicSyncStatus;
use App\Models\Playlist;
use App\Models\YouTubeMusicSync;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'youtubeMusicCookieExpired' => fn (): bool => $request->user()
                ?->youTubeMusicAccount()
                ->whereNotNull('cookie_expired_at')
                ->exists() ?? false,
            'library' => fn (): ?LibraryData => $this->library($request),
        ];
    }

    private function library(Request $request): ?LibraryData
    {
        $account = $request->user()?->youTubeMusicAccount()->first();

        if ($account === null) {
            return null;
        }

        $playlists = $account->playlists()->oldest()->get();

        $activeSync = YouTubeMusicSync::query()
            ->where('youtube_music_account_id', $account->id)
            ->whereIn('status', [YouTubeMusicSyncStatus::Pending, YouTubeMusicSyncStatus::Syncing])
            ->latest('created_at')
            ->first();

        return new LibraryData(
            accountName: $account->account_name,
            playlists: $playlists->map(fn (Playlist $playlist): LibraryPlaylistData => LibraryPlaylistData::fromModel($playlist))->all(),
            lastCheckedAt: $playlists
                ->sortByDesc(fn (Playlist $playlist): int => $playlist->last_checked_at->getTimestamp())
                ->first()
                ?->last_checked_at
                ->toIso8601String(),
            activeSync: $activeSync !== null ? YouTubeMusicSyncData::fromModel($activeSync) : null,
        );
    }
}
