<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SyncPlaylistsFromYouTubeMusicAction;
use App\Enums\YouTubeMusicSyncStatus;
use App\Events\YouTubeMusicSyncUpdated;
use App\Exceptions\Providers\CredentialsRejected;
use App\Exceptions\Providers\ProviderException;
use App\Exceptions\Providers\ProviderRateLimited;
use App\Models\Playlist;
use App\Models\YouTubeMusicSync;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SyncYouTubeMusicLibrary implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A bad or expired cookie will not start working on retry, so the first
     * exception fails the job. Releases for the rate limit are not
     * exceptions: they may repeat until `retryUntil()`.
     */
    public int $maxExceptions = 1;

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
            (new WithoutOverlapping($this->youTubeMusicAccountId))
                ->dontRelease()
                // A killed worker never releases the lock; let it lapse with
                // the sync it guarded, which is then taken for orphaned.
                ->expireAfter(YouTubeMusicSync::STALE_AFTER_MINUTES * 60),
        ];
    }

    /**
     * Long enough for the hourly YouTube Music budget to refill once.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(hours: 2);
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
        } catch (ProviderRateLimited $exception) {
            // Picks up where it stopped: playlists already checked keep
            // their fingerprint and are skipped on the next attempt. Pending
            // (and a fresh updated_at) tells StartYouTubeMusicSync the sync
            // is paused, not orphaned.
            $sync->update(['status' => YouTubeMusicSyncStatus::Pending]);
            broadcast(new YouTubeMusicSyncUpdated($sync));
            $this->release($exception->retryAfter);

            return;
        } catch (ProviderException $exception) {
            report($exception);

            if ($exception instanceof CredentialsRejected) {
                $sync->youtubeMusicAccount->markCookieExpired();
            }

            $sync->update([
                'status' => YouTubeMusicSyncStatus::Failed,
                'error_message' => $exception->errorCode()->value,
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

    /**
     * Runs when the job gives up (an unexpected exception, or rate limit
     * releases past `retryUntil()`), so the sync never stays "syncing"
     * forever and a later page load can start a fresh one.
     */
    public function failed(?Throwable $exception): void
    {
        $sync = YouTubeMusicSync::query()->find($this->syncId);

        if ($sync === null || ! $sync->isActive()) {
            return;
        }

        $sync->markInterrupted();
        broadcast(new YouTubeMusicSyncUpdated($sync));
    }
}
