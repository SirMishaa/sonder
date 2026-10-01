<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Provider;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use Carbon\CarbonInterface;
use Database\Factories\RecordingResolutionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which recording a provider's track is. Becomes plan 2's `track_sources`.
 *
 * @property-read string $id
 * @property-read Provider $provider
 * @property-read string $external_id
 * @property-read string|null $recording_id
 * @property-read ResolutionStatus $status
 * @property-read ResolutionMethod|null $method
 * @property-read float|null $confidence
 * @property-read string $query_title
 * @property-read string $query_artist
 * @property-read int $attempts
 * @property-read CarbonInterface|null $resolved_at
 * @property-read CarbonInterface|null $next_attempt_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Recording|null $recording
 */
final class RecordingResolution extends Model
{
    /** @use HasFactory<RecordingResolutionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'provider',
        'external_id',
        'recording_id',
        'status',
        'method',
        'confidence',
        'query_title',
        'query_artist',
        'attempts',
        'resolved_at',
        'next_attempt_at',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'provider' => Provider::class,
            'status' => ResolutionStatus::class,
            'method' => ResolutionMethod::class,
            'confidence' => 'float',
            'attempts' => 'integer',
            'resolved_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Recording, $this>
     */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }
}
