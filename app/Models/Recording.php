<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RecordingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recording as the registries know it (MusicBrainz id and/or ISRC). The
 * seed of plan 2's catalogue `tracks`.
 *
 * @property-read string $id
 * @property-read string|null $mbid
 * @property-read string|null $isrc
 * @property-read string|null $iswc
 * @property-read string $title
 * @property-read string $artist_name
 * @property-read int|null $duration_seconds
 * @property-read CarbonInterface|null $release_date
 * @property-read int|null $lastfm_listeners
 * @property-read int|null $lastfm_playcount
 * @property-read CarbonInterface|null $projected_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class Recording extends Model
{
    /** @use HasFactory<RecordingFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'mbid',
        'isrc',
        'iswc',
        'title',
        'artist_name',
        'duration_seconds',
        'release_date',
        'lastfm_listeners',
        'lastfm_playcount',
        'projected_at',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'duration_seconds' => 'integer',
            'release_date' => 'date',
            'lastfm_listeners' => 'integer',
            'lastfm_playcount' => 'integer',
            'projected_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RecordingResolution, $this>
     */
    public function resolutions(): HasMany
    {
        return $this->hasMany(RecordingResolution::class);
    }

    /**
     * @return HasMany<RecordingContributor, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany(RecordingContributor::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'recording_tags')->withPivot(['source', 'weight']);
    }
}
