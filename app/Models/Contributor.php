<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContributorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A person, group or publisher credited on recordings. The seed of plan 2's
 * catalogue `artists`.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read string $normalized_name
 * @property-read string|null $mbid
 * @property-read string|null $ipi
 */
final class Contributor extends Model
{
    /** @use HasFactory<ContributorFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['name', 'normalized_name', 'mbid', 'ipi'];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return ['id' => 'string'];
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contributor_tags')->withPivot(['source', 'weight']);
    }
}
