<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MusicText;
use Carbon\CarbonInterface;
use Database\Factories\RecordingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recording known by a registry (MusicBrainz id and/or ISRC) or, failing
 * that, by its normalised title and artist. The seed of plan 2's catalogue
 * `tracks`.
 *
 * @property-read string $id
 * @property-read string|null $mbid
 * @property-read string|null $isrc
 * @property-read string|null $iswc
 * @property-read string $title
 * @property-read string $artist_name
 * @property-read string $match_title
 * @property-read string $match_artist
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
     * A recording no registry identifies, known by its title and artist.
     */
    public static function findByName(string $title, string $artist): ?self
    {
        return self::query()
            ->whereNull('mbid')
            ->whereNull('isrc')
            ->where('match_title', MusicText::matchTitle($title))
            ->where('match_artist', MusicText::matchArtist($artist))
            ->first();
    }

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

    public function isNameOnly(): bool
    {
        return $this->mbid === null && $this->isrc === null;
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

    protected static function booted(): void
    {
        self::saving(function (self $recording): void {
            $recording->forceFill([
                'match_title' => MusicText::matchTitle($recording->title),
                'match_artist' => MusicText::matchArtist($recording->artist_name),
            ]);
        });
    }
}
