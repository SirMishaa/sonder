<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetadataSource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day of one chart (`global`, `country:BE`…), kept forever.
 *
 * @property-read string $id
 * @property-read MetadataSource $source
 * @property-read string $chart
 * @property-read CarbonInterface $taken_on
 */
final class ChartSnapshot extends Model
{
    use HasUuids;

    protected $fillable = ['source', 'chart', 'taken_on'];

    /**
     * @return HasMany<ChartEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(ChartEntry::class);
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return ['id' => 'string', 'source' => MetadataSource::class, 'taken_on' => 'date'];
    }
}
