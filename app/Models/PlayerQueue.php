<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListenOrigin;
use Carbon\CarbonInterface;
use Database\Factories\PlayerQueueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's player queue, saved from the browser so it follows them across
 * devices. The browser stays the source of truth while playing; `version`
 * goes up on every save so a device can tell its copy is behind.
 *
 * @property-read string $id
 * @property-read string $user_id
 * @property-read array<int, array{key: string, videoId: string|null, title: string, artists: string, album: string|null, duration: string|null, durationSeconds: int, thumbnailUrl: string|null, playlistId: string|null, queued?: bool, genres?: list<string>}> $tracks
 * @property-read int $current_index
 * @property-read array{playlistId: string|null, title: string}|null $source
 * @property-read ListenOrigin $origin
 * @property-read int $version
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $user
 */
final class PlayerQueue extends Model
{
    /** @use HasFactory<PlayerQueueFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'user_id',
        'tracks',
        'current_index',
        'source',
        'origin',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'tracks' => 'array',
            'current_index' => 'integer',
            'source' => 'array',
            'origin' => ListenOrigin::class,
            'version' => 'integer',
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
