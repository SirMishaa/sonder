<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use Carbon\CarbonInterface;
use Database\Factories\EnrichmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One raw answer of one source endpoint about one subject: the truth the
 * structured tables are projected from.
 *
 * @property-read string $id
 * @property-read string $subject_type
 * @property-read string $subject_key
 * @property-read MetadataSource $source
 * @property-read string $endpoint
 * @property-read EnrichmentStatus $status
 * @property-read int $attempts
 * @property-read array<string, mixed>|null $payload
 * @property-read string|null $error
 * @property-read CarbonInterface|null $fetched_at
 * @property-read CarbonInterface|null $next_attempt_at
 */
final class Enrichment extends Model
{
    /** @use HasFactory<EnrichmentFactory> */
    use HasFactory;

    use HasUuids;

    public const string RECORDING = 'recording';

    public const string CONTRIBUTOR = 'contributor';

    public const string SOURCE = 'source';

    protected $fillable = [
        'subject_type',
        'subject_key',
        'source',
        'endpoint',
        'status',
        'attempts',
        'payload',
        'error',
        'fetched_at',
        'next_attempt_at',
    ];

    /**
     * Records the latest answer for this subject and endpoint, replacing the
     * previous one. A failure keeps the last good payload.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function store(
        string $subjectType,
        string $subjectKey,
        MetadataSource $source,
        string $endpoint,
        EnrichmentStatus $status,
        ?array $payload = null,
        ?string $error = null,
    ): self {
        $enrichment = self::query()->createOrFirst([
            'subject_type' => $subjectType,
            'subject_key' => $subjectKey,
            'source' => $source,
            'endpoint' => $endpoint,
        ], ['status' => $status]);

        $failed = $status === EnrichmentStatus::Failed;

        $enrichment->update([
            'status' => $status,
            'attempts' => $failed ? $enrichment->attempts + 1 : 0,
            'payload' => $failed ? $enrichment->payload : $payload,
            'error' => $error,
            'fetched_at' => $failed ? $enrichment->fetched_at : now(),
            'next_attempt_at' => $status->nextAttemptAt(),
        ]);

        return $enrichment;
    }

    /**
     * The last payload a source returned for this subject, if any.
     *
     * @return array<string, mixed>|null
     */
    public static function payloadFor(string $subjectType, string $subjectKey, MetadataSource $source, string $endpoint): ?array
    {
        return self::query()
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->where('source', $source)
            ->where('endpoint', $endpoint)
            ->whereNotNull('payload')
            ->first()
            ?->payload;
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'source' => MetadataSource::class,
            'status' => EnrichmentStatus::class,
            'attempts' => 'integer',
            'payload' => 'array',
            'fetched_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }
}
