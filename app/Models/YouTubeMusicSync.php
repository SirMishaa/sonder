<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\YouTubeMusicSyncStatus;
use Carbon\CarbonInterface;
use Database\Factories\YouTubeMusicSyncFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $youtube_music_account_id
 * @property-read YouTubeMusicSyncStatus $status
 * @property-read int|null $total_playlists
 * @property-read int $synced_playlists
 * @property-read string|null $current_playlist_title
 * @property-read string|null $error_message
 * @property-read CarbonInterface|null $started_at
 * @property-read CarbonInterface|null $finished_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read YouTubeMusicAccount $youtubeMusicAccount
 */
#[Table(name: 'youtube_music_syncs')]
final class YouTubeMusicSync extends Model
{
    /** @use HasFactory<YouTubeMusicSyncFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Minutes without progress after which a running sync is taken for
     * orphaned: the job updates the row after every playlist, so silence
     * this long means its worker died (crash, restart, deploy).
     */
    public const int STALE_AFTER_MINUTES = 5;

    /**
     * A sync paused by the call budget waits as pending until its job would
     * give up retrying (`SyncYouTubeMusicLibrary::retryUntil()`).
     */
    public const int PAUSED_STALE_AFTER_MINUTES = 120;

    protected $fillable = [
        'youtube_music_account_id',
        'status',
        'total_playlists',
        'synced_playlists',
        'current_playlist_title',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'youtube_music_account_id' => 'string',
            'status' => YouTubeMusicSyncStatus::class,
            'total_playlists' => 'integer',
            'synced_playlists' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<YouTubeMusicAccount, $this>
     */
    public function youtubeMusicAccount(): BelongsTo
    {
        return $this->belongsTo(YouTubeMusicAccount::class, 'youtube_music_account_id');
    }

    /**
     * Whether the given user owns the account this sync belongs to. This is
     * the single source of truth `routes/channels.php` authorizes against.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->youtubeMusicAccount->user_id === $user->id;
    }

    /**
     * Pending or syncing, whether or not a worker is still behind it.
     */
    public function isActive(): bool
    {
        return in_array($this->status, [YouTubeMusicSyncStatus::Pending, YouTubeMusicSyncStatus::Syncing], true);
    }

    /**
     * Active on paper, but nothing has advanced it for too long to still have
     * a worker behind it.
     */
    public function isStale(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $limit = $this->status === YouTubeMusicSyncStatus::Syncing ? self::STALE_AFTER_MINUTES : self::PAUSED_STALE_AFTER_MINUTES;

        return $this->updated_at->isBefore(now()->subMinutes($limit));
    }

    /**
     * Closes a sync whose job will never finish it. Playlists it already
     * checked keep their fingerprint, so the next sync skips them and in
     * effect resumes where this one stopped.
     */
    public function markInterrupted(): void
    {
        $this->update([
            'status' => YouTubeMusicSyncStatus::Failed,
            'error_message' => 'The sync stopped before it could finish.',
            'finished_at' => now(),
        ]);
    }
}
