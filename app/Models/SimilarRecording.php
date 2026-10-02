<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetadataSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A track a source finds similar to a recording, mostly outside the library,
 * so known by its title and artist only.
 *
 * @property-read string $id
 * @property-read string $recording_id
 * @property-read string $title
 * @property-read string $artist_name
 * @property-read float $match
 * @property-read MetadataSource $source
 */
final class SimilarRecording extends Model
{
    use HasUuids;

    protected $fillable = ['recording_id', 'title', 'artist_name', 'match', 'source'];

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
        return ['id' => 'string', 'match' => 'float', 'source' => MetadataSource::class];
    }
}
