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
}
