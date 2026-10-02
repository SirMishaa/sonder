<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetadataSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An artist a source finds similar to a contributor, known by name only.
 *
 * @property-read string $id
 * @property-read string $contributor_id
 * @property-read string $name
 * @property-read float $match
 * @property-read MetadataSource $source
 */
final class SimilarContributor extends Model
{
    use HasUuids;

    protected $fillable = ['contributor_id', 'name', 'match', 'source'];

    /**
     * @return BelongsTo<Contributor, $this>
     */
    public function contributor(): BelongsTo
    {
        return $this->belongsTo(Contributor::class);
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return ['id' => 'string', 'match' => 'float', 'source' => MetadataSource::class];
    }
}
