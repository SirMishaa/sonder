<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\YouTubeMusicSyncStatus;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;

/**
 * Starts a background sync when the last attempt is more than an hour old,
 * and otherwise returns whichever sync is already running, if any.
 *
 * Staleness is measured from the last attempt, whatever its outcome, rather
 * than from when playlists were last checked: a sync that fails or finds
 * nothing leaves the playlists untouched, and every page load would then
 * start another one. An expired cookie never starts one on its own; the
 * user is asked to replace it instead.
 */
final readonly class CheckLibraryFreshness
{
    private const int STALE_AFTER_MINUTES = 60;

    public function __construct(private StartYouTubeMusicSync $startSync) {}

    public function handle(YouTubeMusicAccount $account): ?YouTubeMusicSync
    {
        $latest = YouTubeMusicSync::query()
            ->where('youtube_music_account_id', $account->id)
            ->latest('created_at')
            ->first();

        $isRunning = $latest !== null
            && in_array($latest->status, [YouTubeMusicSyncStatus::Pending, YouTubeMusicSyncStatus::Syncing], true);
        $isFresh = $latest !== null && $latest->created_at->isAfter(now()->subMinutes(self::STALE_AFTER_MINUTES));

        if ($isRunning || $isFresh || $account->hasExpiredCookie()) {
            return $isRunning ? $latest : null;
        }

        // `StartYouTubeMusicSync::handle()` returns the in-memory instance
        // created before the job was dispatched. Under the sync queue driver
        // the job runs inline and mutates the DB row immediately, so this
        // refresh keeps the rendered sync state current in every environment.
        return $this->startSync->handle($account)->refresh();
    }
}
