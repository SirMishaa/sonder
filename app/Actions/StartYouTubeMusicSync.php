<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\YouTubeMusicSyncStatus;
use App\Events\YouTubeMusicSyncUpdated;
use App\Jobs\SyncYouTubeMusicLibrary;
use App\Models\YouTubeMusicAccount;
use App\Models\YouTubeMusicSync;
use Illuminate\Support\Facades\Cache;

final readonly class StartYouTubeMusicSync
{
    /**
     * Reuses an active (pending or syncing) sync for the account if one
     * exists and still has a worker behind it; an orphaned one (see
     * YouTubeMusicSync::isStale()) is closed as interrupted and replaced.
     * Otherwise creates one and dispatches the job. The check and
     * the creation happen under a lock so two near-simultaneous requests
     * (e.g. two tabs) can never both create a sync for the same account.
     */
    public function handle(YouTubeMusicAccount $account): YouTubeMusicSync
    {
        return Cache::lock('youtube-music-sync-start:'.$account->id, 10)
            ->block(5, function () use ($account): YouTubeMusicSync {
                $existing = YouTubeMusicSync::query()
                    ->where('youtube_music_account_id', $account->id)
                    ->whereIn('status', [YouTubeMusicSyncStatus::Pending, YouTubeMusicSyncStatus::Syncing])
                    ->latest('created_at')
                    ->first();

                if ($existing !== null && ! $existing->isStale()) {
                    return $existing;
                }

                if ($existing !== null) {
                    $existing->markInterrupted();
                    broadcast(new YouTubeMusicSyncUpdated($existing));
                }

                $sync = YouTubeMusicSync::query()->create([
                    'youtube_music_account_id' => $account->id,
                    'status' => YouTubeMusicSyncStatus::Pending,
                ]);

                SyncYouTubeMusicLibrary::dispatch($sync->id, $account->id);

                return $sync;
            });
    }
}
