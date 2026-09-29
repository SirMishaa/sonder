<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $youtube_music_account_id
 * @property string $youtube_playlist_id
 * @property string $title
 * @property string|null $description
 * @property int|null $track_count
 * @property string|null $duration
 * @property string|null $thumbnail_url
 * @property string|null $author
 * @property string|null $fingerprint
 * @property CarbonInterface $last_checked_at
 * @property CarbonInterface|null $last_changed_at
 * @property CarbonInterface|null $removed_at
 */
final class Playlist extends Model
{
    /** @use HasFactory<\Database\Factories\PlaylistFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'youtube_music_account_id',
        'youtube_playlist_id',
        'title',
        'description',
        'track_count',
        'duration',
        'thumbnail_url',
        'author',
        'fingerprint',
        'last_checked_at',
        'last_changed_at',
        'removed_at',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'last_changed_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<YouTubeMusicAccount, $this>
     */
    public function youtubeMusicAccount(): BelongsTo
    {
        return $this->belongsTo(YouTubeMusicAccount::class, 'youtube_music_account_id');
    }

    /**
     * @return HasMany<Track, $this>
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderBy('position');
    }
}
