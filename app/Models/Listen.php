<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListenEndReason;
use App\Enums\ListenOrigin;
use Carbon\CarbonInterface;
use Database\Factories\ListenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One listen of one track. Linked to tracks by YouTube ids rather than
 * foreign keys: the library sync deletes and recreates tracks.
 *
 * @property-read string $id
 * @property-read string $user_id
 * @property-read string $youtube_video_id
 * @property-read string $title
 * @property-read string $artists
 * @property-read string|null $youtube_playlist_id
 * @property-read ListenOrigin $origin
 * @property-read ListenEndReason $end_reason
 * @property-read CarbonInterface $started_at
 * @property-read CarbonInterface $ended_at
 * @property-read int $position_seconds
 * @property-read int $listened_seconds
 * @property-read int|null $duration_seconds
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $user
 */
final class Listen extends Model
{
    /** @use HasFactory<ListenFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'user_id',
        'youtube_video_id',
        'title',
        'artists',
        'youtube_playlist_id',
        'origin',
        'end_reason',
        'started_at',
        'ended_at',
        'position_seconds',
        'listened_seconds',
        'duration_seconds',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'origin' => ListenOrigin::class,
            'end_reason' => ListenEndReason::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'position_seconds' => 'integer',
            'listened_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
