<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetadataSource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One reading of a recording's or contributor's listeners and play count,
 * kept forever so trends can be computed.
 *
 * @property-read string $id
 * @property-read string $subject_type
 * @property-read string $subject_id
 * @property-read MetadataSource $source
 * @property-read int $listeners
 * @property-read int|null $playcount
 * @property-read CarbonInterface $measured_at
 */
final class PopularitySample extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['subject_type', 'subject_id', 'source', 'listeners', 'playcount', 'measured_at'];

    /**
     * Appends a reading unless one at least as recent is already kept.
     */
    public static function record(string $subjectType, string $subjectId, MetadataSource $source, int $listeners, ?int $playcount, CarbonInterface $measuredAt): void
    {
        $known = self::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('source', $source)
            ->where('measured_at', '>=', $measuredAt)
            ->exists();

        if (! $known) {
            self::query()->create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'source' => $source,
                'listeners' => $listeners,
                'playcount' => $playcount,
                'measured_at' => $measuredAt,
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'source' => MetadataSource::class,
            'listeners' => 'integer',
            'playcount' => 'integer',
            'measured_at' => 'datetime',
        ];
    }
}
