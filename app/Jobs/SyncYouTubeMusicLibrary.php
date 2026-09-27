<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SyncPlaylistsFromYouTubeMusicAction;
use App\Enums\YouTubeMusicSyncStatus;
use App\Events\YouTubeMusicSyncUpdated;
use App\Exceptions\YouTubeMusicException;
use App\Models\Playlist;
use App\Models\YouTubeMusicSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SyncYouTubeMusicLibrary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A bad or expired cookie will not start working on retry, so failing
     * fast (and reporting it) beats silently retrying a doomed job.
     */
    public int $tries = 1;

    public function __construct(
        public readonly string $syncId,
        public readonly string $youTubeMusicAccountId,
    ) {}

    /**
     * A queue-level backstop: `StartYouTubeMusicSync` already avoids creating
     * a second sync row for an account that has one in flight, but if a race
     * ever slips through, only one job per account should actually run.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->youTubeMusicAccountId))->dontRelease(),
        ];
    }

    public function handle(SyncPlaylistsFromYouTubeMusicAction $action): void
    {
        $sync = YouTubeMusicSync::query()->with('youtubeMusicAccount')->findOrFail($this->syncId);

        $sync->update([
            'status' => YouTubeMusicSyncStatus::Syncing,
            'started_at' => now(),
        ]);
        broadcast(new YouTubeMusicSyncUpdated($sync));

        try {
            $action->handle(
                $sync->youtubeMusicAccount,
                function (int $synced, int $total, ?Playlist $playlist) use ($sync): void {
                    $sync->update([
                        'total_playlists' => $total,
                        'synced_playlists' => $synced,
                        'current_playlist_title' => $playlist?->title,
                    ]);
                    broadcast(new YouTubeMusicSyncUpdated($sync));
                },
            );
        } catch (YouTubeMusicException $exception) {
            $sync->youtubeMusicAccount->markCookieExpired();
            $sync->update([
                'status' => YouTubeMusicSyncStatus::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
            broadcast(new YouTubeMusicSyncUpdated($sync));

            return;
        }

        $sync->youtubeMusicAccount->markCookieWorking();
        $sync->update([
            'status' => YouTubeMusicSyncStatus::Completed,
            'finished_at' => now(),
        ]);
        broadcast(new YouTubeMusicSyncUpdated($sync));
    }
}
