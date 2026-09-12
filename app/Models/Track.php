<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
