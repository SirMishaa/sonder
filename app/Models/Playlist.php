<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
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
