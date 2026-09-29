<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;

/**
 * Starts a background sync when the library has not been checked for an hour,
 * and otherwise returns whichever sync is already running, if any.
 */
final readonly class CheckLibraryFreshness
{
    private const int STALE_AFTER_MINUTES = 60;

    public function __construct(private StartYouTubeMusicSync $startSync) {}

    public function handle(YouTubeMusicAccount $account): ?YouTubeMusicSync
    {
        $sync = $account->playlists()->max('last_checked_at') < now()->subMinutes(self::STALE_AFTER_MINUTES)
            ? $this->startSync->handle($account)
            : YouTubeMusicSync::query()
                ->where('youtube_music_account_id', $account->id)
                ->whereIn('status', [YouTubeMusicSyncStatus::Pending, YouTubeMusicSyncStatus::Syncing])
                ->latest('created_at')
                ->first();

        // `StartYouTubeMusicSync::handle()` returns the in-memory instance
        // created before the job was dispatched. Under the sync queue driver
        // the job runs inline and mutates the DB row immediately, so this
        // refresh keeps the rendered sync state current in every environment.
        return $sync?->refresh();
    }
}
