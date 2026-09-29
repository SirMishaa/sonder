<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $playlist_id
 * @property-read string|null $youtube_video_id
 * @property-read string $title
 * @property-read string $artists
 * @property-read string|null $album
 * @property-read string|null $duration
 * @property-read int|null $duration_seconds
 * @property-read string|null $thumbnail_url
 * @property-read bool $is_explicit
 * @property-read bool $is_available
 * @property-read int $position
 * @property-read Playlist $playlist
 */
final class Track extends Model
{
    use HasUuids;

    protected $fillable = [
        'playlist_id',
        'youtube_video_id',
        'title',
        'artists',
        'album',
        'duration',
        'duration_seconds',
        'thumbnail_url',
        'is_explicit',
        'is_available',
        'position',
    ];

    protected $casts = [
        'is_explicit' => 'boolean',
        'is_available' => 'boolean',
    ];

    /**
     * @return BelongsTo<Playlist, $this>
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
