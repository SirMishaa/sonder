<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditType;
use App\Enums\MetadataSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One credit: a contributor's part on a recording, as one source states it.
 *
 * @property-read string $id
 * @property-read string $recording_id
 * @property-read string $contributor_id
 * @property-read CreditType $credit_type
 * @property-read string $role
 * @property-read list<string> $credit_attributes
 * @property-read MetadataSource $source
 * @property-read Contributor $contributor
 */
final class RecordingContributor extends Model
{
    use HasUuids;

    protected $fillable = ['recording_id', 'contributor_id', 'credit_type', 'role', 'credit_attributes', 'source'];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'credit_type' => CreditType::class,
            'credit_attributes' => 'array',
            'source' => MetadataSource::class,
        ];
    }

    /**
     * @return BelongsTo<Contributor, $this>
     */
    public function contributor(): BelongsTo
    {
        return $this->belongsTo(Contributor::class);
    }
}
