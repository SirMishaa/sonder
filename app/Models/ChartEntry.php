<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A track's rank in a chart snapshot, linked to a recording when the library
 * knows it.
 *
 * @property-read string $id
 * @property-read string $chart_snapshot_id
 * @property-read int $rank
 * @property-read string $title
 * @property-read string $artist_name
 * @property-read int|null $listeners
 * @property-read int|null $playcount
 * @property-read string|null $recording_id
 */
final class ChartEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['chart_snapshot_id', 'rank', 'title', 'artist_name', 'listeners', 'playcount', 'recording_id'];

    /**
     * @return BelongsTo<Recording, $this>
     */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return ['id' => 'string', 'rank' => 'integer', 'listeners' => 'integer', 'playcount' => 'integer'];
    }
}
