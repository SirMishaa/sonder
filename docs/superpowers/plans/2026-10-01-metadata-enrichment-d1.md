# Metadata Enrichment D1 (Data Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve every library track to a registry recording (MusicBrainz id and/or ISRC) and store its credits, genres and tags from credits.fm and MusicBrainz, in the background, rate limited and observable.

**Architecture:** Raw HTTP gateways per source (credits.fm, MusicBrainz) behind interfaces, wrapped by rate-limited decorators that spend a named Laravel limiter through a shared `CallBudget`; pure mappers turn payloads into Data objects. Actions resolve tracks, store raw payloads in `enrichments`, and project them into structured tables. Queued jobs on an `enrichment` queue chain the steps; commands and the post-sync hook feed them.

**Tech Stack:** Laravel 13 (PHP 8.5), PostgreSQL, Pest, Laravel HTTP client, keepsuit/laravel-opentelemetry (Meter, Tracer), database queue.

**Spec:** `docs/superpowers/specs/2026-10-01-metadata-enrichment-design.md` (read it first; this plan implements its D1 part).

## Global Constraints

- Every PHP file: `declare(strict_types=1);`, final classes, constructor promotion, explicit types, curly braces, concise class/method docblocks, strongest PHPStan generics. No inline comments except for genuinely non-obvious logic.
- Actions live in `app/Actions`, have no suffix, one `handle()` method.
- New files via `php artisan make:* --no-interaction` where a generator exists.
- After PHP changes: `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyze --level 8 <files>`.
- Tests: Pest; run narrow with `vendor/bin/pest <path>`; final run `./vendor/bin/pest --parallel`.
- `tests/Pest.php` already calls `Http::preventStrayRequests()`, `Sleep::fake()` and `freezeTime()` for every test; never hit the network in tests.
- Commits: Conventional Commits, `type(scope): subject`, lower case, no trailing period, **no `Co-Authored-By` or any AI attribution**. Before each commit, check the GPG key: `echo test | gpg --batch --pinentry-mode error --local-user $(git config user.signingkey) -s -o /dev/null; echo "exit=$?"`. Non-zero: stop and ask the user to unlock it.
- Rate limits (from the spec): `credits-fm` 2/s and 3 000/h; `musicbrainz` 1/s.
- MusicBrainz requires the User-Agent `Sonder/<version> ( <contact> )`. The contact comes from `MUSICBRAINZ_CONTACT` in `.env`; never hardcode an email.
- Re-check intervals: done data 90 days, `not_found` 30 days, `failed` 1 day.
- Resolution acceptance: a MusicBrainz recording within 5 seconds of the source duration and with a matching normalised artist (confidence 1.0); a credits.fm ISRC whose detail title and artist match (confidence 0.6); a MusicBrainz search hit with score ≥ 90, matching artist and duration within 5 s (confidence 0.9).
- Jobs: queue `enrichment`, `retryUntil()` 2 h, `$maxExceptions = 3`, `backoff()` `[60, 600, 3600]`, release on `MetadataSourceRateLimited` for `retryAfter` seconds.

## Review Focus

- credits.fm `resolve/batch` returns an ISRC for nonsense queries → that ISRC must be rejected when MusicBrainz does not know it and the credits.fm detail does not match (Task 6 test "rejects an isrc credits.fm made up").
- A track whose source duration is unknown (`duration_seconds` null) → the duration check is skipped for the ISRC path, but a search hit needs a score of 100 (Task 6 test "accepts a search hit without a duration only at full score").
- A YouTube title "Artist - Title" uploaded by a fan channel, and a real title containing " - " → both readings are tried, the verified one wins (Task 2 dataset + Task 6 test "tries the title both ways").
- Re-running the pipeline on the same recording → projection rebuilds the same rows, no duplicates (Task 8 test "projects twice without duplicating").
- A service returning 503 or 429 → the job is released, the attempt is not counted, no `failed` row is written (Task 9 test "releases on a rate limit without recording a failure").

---

## File Structure

```
app/Enums/MetadataSource.php                 credits_fm, musicbrainz, lastfm, youtube_music
app/Enums/EnrichmentStatus.php               done, not_found, failed (+ nextAttemptAt())
app/Enums/ResolutionStatus.php               resolved, not_found, failed
app/Enums/ResolutionMethod.php               credits_fm, musicbrainz_search
app/Enums/CreditType.php                     artist, songwriter, publisher, producer, performer
app/Enums/LookupOutcome.php                  found, not_found, failed
app/Models/{Recording,RecordingResolution,Contributor,RecordingContributor,Tag,Enrichment}.php
database/migrations/…_create_metadata_enrichment_tables.php
database/factories/{Recording,RecordingResolution,Contributor,Enrichment}Factory.php
app/Support/MusicText.php                    normalisation + query building
app/Exceptions/Metadata/{MetadataSourceRateLimited,MetadataSourceUnavailable}.php
app/Services/Metadata/CallBudget.php
app/Services/Metadata/EnrichmentTelemetry.php                  interface
app/Services/Metadata/OpenTelemetryEnrichmentTelemetry.php
app/Services/Metadata/Data/{TrackQuery,RegistryRecording,Credit,WeightedTag,IsrcDetail}.php
app/Services/Metadata/CreditsFm/{CreditsFmGateway,HttpCreditsFmGateway,RateLimitedCreditsFmGateway,CreditsFmMapper}.php
app/Services/Metadata/MusicBrainz/{MusicBrainzGateway,HttpMusicBrainzGateway,RateLimitedMusicBrainzGateway,MusicBrainzMapper}.php
app/Actions/{ResolveRecordings,DescribeRecording,DescribeContributor,ProjectEnrichment,ProjectContributorTags,QueueTrackResolution,RecordEnrichmentCoverage}.php
app/Jobs/{ResolveLibraryTracks,EnrichRecording,EnrichContributor,ProjectRecordingMetadata}.php
app/Console/Commands/{EnrichMetadataCommand,RetryDueMetadataCommand,ReprojectMetadataCommand}.php
tests/Fixtures/Metadata/*.json               real responses captured 2026-10-01
tests/Support/{FakeCreditsFmGateway,FakeMusicBrainzGateway,FakeEnrichmentTelemetry}.php
```

---

### Task 1: Enums, tables and models

**Files:**
- Create: `app/Enums/{MetadataSource,EnrichmentStatus,ResolutionStatus,ResolutionMethod,CreditType,LookupOutcome}.php`
- Create: migration `create_metadata_enrichment_tables`
- Create: `app/Models/{Recording,RecordingResolution,Contributor,RecordingContributor,Tag,Enrichment}.php` and factories for Recording, RecordingResolution, Contributor, Enrichment
- Test: `tests/Unit/Models/RecordingTest.php`, `tests/Unit/Models/EnrichmentTest.php`

**Interfaces:**
- Produces: the enums below (exact cases); models with the relations `Recording::resolutions()`, `Recording::credits()` (HasMany RecordingContributor), `Recording::tags()` (BelongsToMany Tag via `recording_tags` with pivot `source`, `weight`), `Contributor::tags()` (via `contributor_tags`), `Enrichment::store(string $subjectType, string $subjectKey, MetadataSource $source, string $endpoint, EnrichmentStatus $status, ?array $payload = null, ?string $error = null): Enrichment`, `Enrichment::payloadFor(string $subjectType, string $subjectKey, MetadataSource $source, string $endpoint): ?array`.

- [ ] **Step 1: Create the enums**

```php
// app/Enums/MetadataSource.php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A service Sonder reads metadata from. Not a streaming provider.
 */
enum MetadataSource: string
{
    case CreditsFm = 'credits_fm';
    case MusicBrainz = 'musicbrainz';
    case LastFm = 'lastfm';
    case YouTubeMusic = 'youtube_music';
}
```

```php
// app/Enums/EnrichmentStatus.php
<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum EnrichmentStatus: string
{
    case Done = 'done';
    case NotFound = 'not_found';
    case Failed = 'failed';

    /**
     * When data in this state is worth asking for again.
     */
    public function nextAttemptAt(): CarbonImmutable
    {
        return match ($this) {
            self::Done => CarbonImmutable::now()->addDays(90),
            self::NotFound => CarbonImmutable::now()->addDays(30),
            self::Failed => CarbonImmutable::now()->addDay(),
        };
    }
}
```

```php
// app/Enums/ResolutionStatus.php
<?php

declare(strict_types=1);

namespace App\Enums;

enum ResolutionStatus: string
{
    case Resolved = 'resolved';
    case NotFound = 'not_found';
    case Failed = 'failed';
}
```

```php
// app/Enums/ResolutionMethod.php
<?php

declare(strict_types=1);

namespace App\Enums;

enum ResolutionMethod: string
{
    case CreditsFm = 'credits_fm';
    case MusicBrainzSearch = 'musicbrainz_search';
}
```

```php
// app/Enums/CreditType.php
<?php

declare(strict_types=1);

namespace App\Enums;

enum CreditType: string
{
    case Artist = 'artist';
    case Songwriter = 'songwriter';
    case Publisher = 'publisher';
    case Producer = 'producer';
    case Performer = 'performer';
}
```

```php
// app/Enums/LookupOutcome.php
<?php

declare(strict_types=1);

namespace App\Enums;

enum LookupOutcome: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Failed = 'failed';
}
```

- [ ] **Step 2: Create the migration**

Run: `php artisan make:migration create_metadata_enrichment_tables --no-interaction`, then replace its body:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('mbid')->nullable()->unique();
            $table->string('isrc', 12)->nullable()->index();
            $table->string('iswc')->nullable();
            $table->string('title');
            $table->string('artist_name');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->date('release_date')->nullable();
            $table->unsignedBigInteger('lastfm_listeners')->nullable();
            $table->unsignedBigInteger('lastfm_playcount')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE recordings ADD CONSTRAINT recordings_identified CHECK (mbid IS NOT NULL OR isrc IS NOT NULL)');

        Schema::create('recording_resolutions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('external_id');
            $table->foreignUuid('recording_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('method')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('query_title');
            $table->string('query_artist');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('contributors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->uuid('mbid')->nullable()->unique();
            $table->string('ipi', 11)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('recording_contributors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recording_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contributor_id')->index()->constrained()->cascadeOnDelete();
            $table->string('credit_type');
            $table->string('role')->default('');
            $table->json('attributes');
            $table->string('source');
            $table->timestamps();

            $table->unique(['recording_id', 'contributor_id', 'credit_type', 'role', 'source'], 'recording_contributors_unique');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_genre')->default(false);
            $table->timestamps();
        });

        Schema::create('recording_tags', function (Blueprint $table): void {
            $table->foreignUuid('recording_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->index()->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->unsignedTinyInteger('weight');

            $table->primary(['recording_id', 'tag_id', 'source']);
        });

        Schema::create('contributor_tags', function (Blueprint $table): void {
            $table->foreignUuid('contributor_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->index()->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->unsignedTinyInteger('weight');

            $table->primary(['contributor_id', 'tag_id', 'source']);
        });

        Schema::create('enrichments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->string('subject_key');
            $table->string('source');
            $table->string('endpoint');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_key', 'source', 'endpoint'], 'enrichments_unique');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichments');
        Schema::dropIfExists('contributor_tags');
        Schema::dropIfExists('recording_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('recording_contributors');
        Schema::dropIfExists('contributors');
        Schema::dropIfExists('recording_resolutions');
        Schema::dropIfExists('recordings');
    }
};
```

- [ ] **Step 3: Create the models**

Run `php artisan make:model <Name> --no-interaction` for each (add `-f` for Recording, RecordingResolution, Contributor, Enrichment), then write:

```php
// app/Models/Recording.php
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
```

```php
// app/Models/RecordingResolution.php
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
```

```php
// app/Models/Contributor.php
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
```

```php
// app/Models/RecordingContributor.php
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
 * @property-read list<string> $attributes
 * @property-read MetadataSource $source
 * @property-read Contributor $contributor
 */
final class RecordingContributor extends Model
{
    use HasUuids;

    protected $fillable = ['recording_id', 'contributor_id', 'credit_type', 'role', 'attributes', 'source'];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'credit_type' => CreditType::class,
            'attributes' => 'array',
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
```

```php
// app/Models/Tag.php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MusicText;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A genre or a free tag, shared by every source.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read string $slug
 * @property-read bool $is_genre
 */
final class Tag extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'slug', 'is_genre'];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return ['id' => 'string', 'is_genre' => 'boolean'];
    }

    /**
     * The tag for this name, created when new. A name once seen as a genre
     * stays a genre.
     */
    public static function named(string $name, bool $isGenre): self
    {
        $tag = self::query()->createOrFirst(
            ['slug' => MusicText::tagSlug($name)],
            ['name' => mb_strtolower(trim($name)), 'is_genre' => $isGenre],
        );

        if ($isGenre && ! $tag->is_genre) {
            $tag->update(['is_genre' => true]);
        }

        return $tag;
    }
}
```

```php
// app/Models/Enrichment.php
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

    /**
     * Records the latest answer for this subject and endpoint, replacing the
     * previous one.
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

        $enrichment->update([
            'status' => $status,
            'attempts' => $status === EnrichmentStatus::Failed ? $enrichment->attempts + 1 : 0,
            'payload' => $status === EnrichmentStatus::Failed ? $enrichment->payload : $payload,
            'error' => $error,
            'fetched_at' => $status === EnrichmentStatus::Failed ? $enrichment->fetched_at : now(),
            'next_attempt_at' => $status->nextAttemptAt(),
        ]);

        return $enrichment;
    }

    /**
     * The stored payload of a successful answer, if any.
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
            ->where('status', EnrichmentStatus::Done)
            ->first()
            ?->payload;
    }
}
```

Factories (each `final`, `@extends Factory<Model>`):

```php
// database/factories/RecordingFactory.php  definition()
return [
    'mbid' => fake()->uuid(),
    'isrc' => strtoupper(fake()->bothify('??###########')),
    'title' => fake()->sentence(3),
    'artist_name' => fake()->name(),
    'duration_seconds' => fake()->numberBetween(120, 360),
];

// database/factories/RecordingResolutionFactory.php  definition()
return [
    'provider' => Provider::YouTubeMusic,
    'external_id' => fake()->regexify('[A-Za-z0-9_-]{11}'),
    'recording_id' => Recording::factory(),
    'status' => ResolutionStatus::Resolved,
    'method' => ResolutionMethod::CreditsFm,
    'confidence' => 1.0,
    'query_title' => fake()->sentence(3),
    'query_artist' => fake()->name(),
    'resolved_at' => now(),
    'next_attempt_at' => now()->addDays(90),
];

// database/factories/ContributorFactory.php  definition()
$name = fake()->name();
return [
    'name' => $name,
    'normalized_name' => MusicText::normalize($name),
    'mbid' => fake()->uuid(),
    'ipi' => null,
];

// database/factories/EnrichmentFactory.php  definition()
return [
    'subject_type' => Enrichment::RECORDING,
    'subject_key' => fake()->uuid(),
    'source' => MetadataSource::MusicBrainz,
    'endpoint' => 'recording',
    'status' => EnrichmentStatus::Done,
    'payload' => [],
    'fetched_at' => now(),
    'next_attempt_at' => now()->addDays(90),
];
```

`ContributorFactory` and `Tag::named()` use `App\Support\MusicText` from Task 2: run this task's tests at the end of Task 2 (Step 4 there includes them), and commit Task 1 then.

- [ ] **Step 4: Write the failing tests**

```php
// tests/Unit/Models/RecordingTest.php
<?php

declare(strict_types=1);

use App\Models\Recording;
use Illuminate\Database\QueryException;

it('refuses a recording no registry identifies', function (): void {
    expect(fn () => Recording::factory()->create(['mbid' => null, 'isrc' => null]))
        ->toThrow(QueryException::class);
});

it('lets two recordings share an isrc', function (): void {
    Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    Recording::factory()->create(['isrc' => 'GBAHT1200434']);

    expect(Recording::query()->where('isrc', 'GBAHT1200434')->count())->toBe(2);
});
```

```php
// tests/Unit/Models/EnrichmentTest.php
<?php

declare(strict_types=1);

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Enrichment;

it('keeps one row per subject, source and endpoint', function (): void {
    Enrichment::store('recording', 'r1', MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, ['a' => 1]);
    Enrichment::store('recording', 'r1', MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, ['a' => 2]);
    Enrichment::store('recording', 'r1', MetadataSource::MusicBrainz, 'recording', EnrichmentStatus::Done, ['b' => 1]);

    expect(Enrichment::query()->count())->toBe(2)
        ->and(Enrichment::payloadFor('recording', 'r1', MetadataSource::CreditsFm, 'isrc'))->toBe(['a' => 2]);
});

it('keeps the last good payload and counts attempts when a refresh fails', function (): void {
    Enrichment::store('recording', 'r1', MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, ['a' => 1]);
    $failed = Enrichment::store('recording', 'r1', MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Failed, error: 'timeout');

    expect($failed->payload)->toBe(['a' => 1])
        ->and($failed->attempts)->toBe(1)
        ->and($failed->next_attempt_at?->toIso8601String())->toBe(now()->addDay()->toIso8601String())
        ->and(Enrichment::payloadFor('recording', 'r1', MetadataSource::CreditsFm, 'isrc'))->toBeNull();
});

it('asks again for a missing answer after thirty days', function (): void {
    $enrichment = Enrichment::store('recording', 'r1', MetadataSource::MusicBrainz, 'recording', EnrichmentStatus::NotFound);

    expect($enrichment->next_attempt_at?->toIso8601String())->toBe(now()->addDays(30)->toIso8601String());
});
```

- [ ] **Step 5: Run the migration and tests**

Run: `php artisan migrate --no-interaction && vendor/bin/pest tests/Unit/Models/RecordingTest.php tests/Unit/Models/EnrichmentTest.php`
Expected: PASS (after Task 2's `MusicText::normalize` exists; if Task 2 is not done yet, run these tests at the end of Task 2).

- [ ] **Step 6: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Enums app/Models database/factories
git add app/Enums app/Models database tests/Unit/Models
git commit -m "feat(enrichment): add recording, contributor, tag and enrichment tables"
```

---

### Task 2: MusicText normalisation

**Files:**
- Create: `app/Support/MusicText.php`, `app/Services/Metadata/Data/TrackQuery.php`
- Test: `tests/Unit/Support/MusicTextTest.php`

**Interfaces:**
- Produces: `MusicText::normalize(string): string`, `MusicText::cleanTitle(string): string`, `MusicText::firstArtist(string): string`, `MusicText::queries(string $title, string $artists): list<TrackQuery>` (1 or 2 readings, most likely first), `MusicText::sameArtist(string, string): bool`, `MusicText::sameTitle(string, string): bool`, `MusicText::tagSlug(string): string`; `TrackQuery(string $title, string $artist)`.

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Support/MusicTextTest.php
<?php

declare(strict_types=1);

use App\Services\Metadata\Data\TrackQuery;
use App\Support\MusicText;

it('cleans the noise YouTube adds to titles and keeps version markers', function (string $title, string $clean): void {
    expect(MusicText::cleanTitle($title))->toBe($clean);
})->with([
    ['Rammstein - Pussy OFFICIAL MUSIC VIDEO', 'Rammstein - Pussy'],
    ['Survival (Official Video)', 'Survival'],
    ['Survival [Lyrics]', 'Survival'],
    ['Helden (feat. Till Lindemann)', 'Helden'],
    ['Royalty (ft. Neoni) - Extended Version', 'Royalty - Extended Version'],
    ['Sing Me to Sleep (Marshmello Remix)', 'Sing Me to Sleep (Marshmello Remix)'],
    ['Sing Me to Sleep (Instrumental)', 'Sing Me to Sleep (Instrumental)'],
    ['No Beef [Vocal Mix] (feat. Miss Palmer)', 'No Beef [Vocal Mix]'],
]);

it('keeps the first credited artist and drops topic channels', function (string $artists, string $first): void {
    expect(MusicText::firstArtist($artists))->toBe($first);
})->with([
    ['Afrojack, Steve Aoki', 'Afrojack'],
    ['Egzod & Maestro Chives', 'Egzod'],
    ['Muse - Topic', 'Muse'],
    ['Apocalyptica', 'Apocalyptica'],
]);

it('reads "Artist - Title" both ways unless the artist already matches', function (string $title, string $artists, array $readings): void {
    expect(array_map(fn (TrackQuery $query): array => [$query->artist, $query->title], MusicText::queries($title, $artists)))
        ->toBe($readings);
})->with([
    'artist repeated in the title' => ['Apashe - Renaissance 2.0', 'Apashe', [['Apashe', 'Renaissance 2.0']]],
    'fan upload' => ['Rammstein - Pussy OFFICIAL MUSIC VIDEO', 'Alına Lındemann', [['Rammstein', 'Pussy'], ['Alına Lındemann', 'Rammstein - Pussy']]],
    'real title with a dash' => ['Bara Bara - Bere Bere (Remix)', 'Alex Ferrari', [['Bara Bara', 'Bere Bere (Remix)'], ['Alex Ferrari', 'Bara Bara - Bere Bere (Remix)']]],
    'plain' => ['Survival', 'Muse', [['Muse', 'Survival']]],
]);

it('compares artists and titles loosely', function (): void {
    expect(MusicText::sameArtist('Alına Lındemann', 'alina lindemann'))->toBeTrue()
        ->and(MusicText::sameArtist('The Prodigy', 'Prodigy'))->toBeTrue()
        ->and(MusicText::sameArtist('Muse', 'Metallica'))->toBeFalse()
        ->and(MusicText::sameTitle('Survival (Official Video)', 'survival'))->toBeTrue()
        ->and(MusicText::sameTitle('Survival (Live)', 'Survival'))->toBeFalse();
});

it('gives one slug to spellings of the same tag', function (): void {
    expect(MusicText::tagSlug('Hip-Hop'))->toBe('hip hop')
        ->and(MusicText::tagSlug(' hip hop '))->toBe('hip hop')
        ->and(MusicText::tagSlug('Drum & Bass'))->toBe('drum and bass');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/Support/MusicTextTest.php`
Expected: FAIL, class `App\Support\MusicText` not found.

- [ ] **Step 3: Implement**

```php
// app/Services/Metadata/Data/TrackQuery.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * A title and artist as asked of a metadata service.
 */
final readonly class TrackQuery
{
    public function __construct(
        public string $title,
        public string $artist,
    ) {}
}
```

```php
// app/Support/MusicText.php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Metadata\Data\TrackQuery;
use Illuminate\Support\Str;

/**
 * Normalises titles and artist names the way providers write them, for
 * querying registries and comparing their answers. Plan 2's matching reuses
 * it for `match_title` / `match_artist`.
 */
final class MusicText
{
    private const string NOISE = '/\s*(?:[\(\[]\s*(?:official\s*)?(?:music\s*)?(?:video|audio|lyrics?(?:\s*video)?|visuali[sz]er|clip(?:\s*officiel)?|hd|hq|4k)\s*[\)\]]|\bofficial\s+(?:music\s+)?(?:video|audio)\b)/iu';

    private const string FEATURING = '/\s*[\(\[]\s*(?:feat\.?|ft\.?|featuring)\s[^\)\]]*[\)\]]/iu';

    public static function normalize(string $text): string
    {
        $ascii = Str::lower(Str::ascii($text));
        $words = preg_replace('/[^a-z0-9]+/', ' ', str_replace('&', ' and ', $ascii)) ?? $ascii;

        return trim(preg_replace('/\s+/', ' ', $words) ?? $words);
    }

    public static function cleanTitle(string $title): string
    {
        $clean = preg_replace([self::NOISE, self::FEATURING], '', $title) ?? $title;

        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean, " \t-");
    }

    public static function firstArtist(string $artists): string
    {
        $withoutTopic = preg_replace('/\s+-\s+topic$/i', '', trim($artists)) ?? $artists;
        $first = preg_split('/\s*(?:,|&|\bx\b|\bfeat\.?|\bft\.?)\s*/iu', $withoutTopic)[0] ?? $withoutTopic;

        return trim($first);
    }

    /**
     * The readings worth asking about, most likely first. "Left - Right"
     * is read as artist and title unless the left part already is the
     * artist; the literal reading is kept as a fallback.
     *
     * @return list<TrackQuery>
     */
    public static function queries(string $title, string $artists): array
    {
        $clean = self::cleanTitle($title);
        $artist = self::firstArtist($artists);
        $parts = explode(' - ', $clean, 2);

        if (count($parts) < 2) {
            return [new TrackQuery($clean, $artist)];
        }

        [$left, $right] = $parts;

        if (self::sameArtist($left, $artist)) {
            return [new TrackQuery($right, $artist)];
        }

        return [
            new TrackQuery($right, self::firstArtist($left)),
            new TrackQuery($clean, $artist),
        ];
    }

    public static function sameArtist(string $a, string $b): bool
    {
        $left = preg_replace('/^the /', '', self::normalize($a)) ?? '';
        $right = preg_replace('/^the /', '', self::normalize($b)) ?? '';

        return $left !== '' && $left === $right;
    }

    public static function sameTitle(string $a, string $b): bool
    {
        $left = self::normalize(self::cleanTitle($a));

        return $left !== '' && $left === self::normalize(self::cleanTitle($b));
    }

    public static function tagSlug(string $name): string
    {
        return self::normalize($name);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Unit/Support/MusicTextTest.php tests/Unit/Models`
Expected: PASS. If a dataset row fails, fix the regular expression, not the expectation: the expectations are the agreed behaviour.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Support app/Services/Metadata/Data
git add app/Support app/Services/Metadata/Data tests/Unit/Support
git commit -m "feat(enrichment): normalise provider titles and artists for registry lookups"
```

---

### Task 3: Call budget, telemetry and metadata exceptions

**Files:**
- Create: `app/Exceptions/Metadata/MetadataSourceRateLimited.php`, `app/Exceptions/Metadata/MetadataSourceUnavailable.php`
- Create: `app/Services/Metadata/CallBudget.php`, `app/Services/Metadata/EnrichmentTelemetry.php`, `app/Services/Metadata/OpenTelemetryEnrichmentTelemetry.php`
- Create: `tests/Support/FakeEnrichmentTelemetry.php`
- Modify: `app/Providers/AppServiceProvider.php` (limiters, bindings), `bootstrap/app.php` (dontReport), `app/Services/Music/YouTubeMusic/Gateway/RateLimitedGateway.php` (refusal metric)
- Test: `tests/Unit/Services/Metadata/CallBudgetTest.php`

**Interfaces:**
- Produces: `MetadataSourceRateLimited(MetadataSource $source, int $retryAfter)` with public `$source`, `$retryAfter`; `MetadataSourceUnavailable(MetadataSource $source, string $reason)` with public `$source`; `CallBudget::spend(string $limiter, string $key = 'global'): ?int` (null = allowed and counted, int = seconds to wait); `CallBudget::CREDITS_FM = 'credits-fm'`, `CallBudget::MUSICBRAINZ = 'musicbrainz'`; interface `EnrichmentTelemetry` with `lookup(MetadataSource, string $endpoint, LookupOutcome, float $seconds): void`, `resolution(ResolutionStatus, ?ResolutionMethod, ?float $confidence): void`, `refusal(string $limiter): void`, `coverage(string $facet, float $ratio): void`, `backlog(string $status, int $count): void`; `FakeEnrichmentTelemetry` with public arrays `$lookups`, `$resolutions`, `$refusals`, `$coverage`, `$backlog`.

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Services/Metadata/CallBudgetTest.php
<?php

declare(strict_types=1);

use App\Services\Metadata\CallBudget;
use App\Services\Metadata\EnrichmentTelemetry;
use Tests\Support\FakeEnrichmentTelemetry;

beforeEach(function (): void {
    $this->telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $this->telemetry);
    $this->budget = resolve(CallBudget::class);
});

it('allows MusicBrainz one call a second', function (): void {
    expect($this->budget->spend(CallBudget::MUSICBRAINZ))->toBeNull()
        ->and($this->budget->spend(CallBudget::MUSICBRAINZ))->toBe(1)
        ->and($this->telemetry->refusals)->toBe(['musicbrainz']);

    $this->travel(1)->seconds();

    expect($this->budget->spend(CallBudget::MUSICBRAINZ))->toBeNull();
});

it('caps credits.fm by the hour as well as by the second', function (): void {
    foreach (range(1, 3000) as $call) {
        if ($call % 2 === 1) {
            $this->travel(1)->seconds();
        }

        expect($this->budget->spend(CallBudget::CREDITS_FM))->toBeNull();
    }

    $this->travel(1)->seconds();

    expect($this->budget->spend(CallBudget::CREDITS_FM))->toBeGreaterThan(1);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/Services/Metadata/CallBudgetTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
// app/Exceptions/Metadata/MetadataSourceRateLimited.php
<?php

declare(strict_types=1);

namespace App\Exceptions\Metadata;

use App\Enums\MetadataSource;
use RuntimeException;

/**
 * Sonder's own budget for a metadata service is spent, or the service asked
 * to slow down. The job releases itself; never shown to a user, never
 * reported.
 */
final class MetadataSourceRateLimited extends RuntimeException
{
    public function __construct(public readonly MetadataSource $source, public readonly int $retryAfter)
    {
        parent::__construct("Too many calls to {$source->value}; the next one is allowed in {$retryAfter} seconds.");
    }
}
```

```php
// app/Exceptions/Metadata/MetadataSourceUnavailable.php
<?php

declare(strict_types=1);

namespace App\Exceptions\Metadata;

use App\Enums\MetadataSource;
use RuntimeException;

/**
 * A metadata service could not answer (network, 5xx, unexpected payload).
 */
final class MetadataSourceUnavailable extends RuntimeException
{
    public function __construct(public readonly MetadataSource $source, string $reason)
    {
        parent::__construct("{$source->value} is unavailable: {$reason}");
    }
}
```

```php
// app/Services/Metadata/EnrichmentTelemetry.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;

/**
 * Metrics, span attributes and logs of the enrichment pipeline.
 */
interface EnrichmentTelemetry
{
    public function lookup(MetadataSource $source, string $endpoint, LookupOutcome $outcome, float $seconds): void;

    public function resolution(ResolutionStatus $status, ?ResolutionMethod $method, ?float $confidence): void;

    public function refusal(string $limiter): void;

    public function coverage(string $facet, float $ratio): void;

    public function backlog(string $status, int $count): void;
}
```

```php
// app/Services/Metadata/OpenTelemetryEnrichmentTelemetry.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use Illuminate\Support\Facades\Log;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;

final class OpenTelemetryEnrichmentTelemetry implements EnrichmentTelemetry
{
    public function lookup(MetadataSource $source, string $endpoint, LookupOutcome $outcome, float $seconds): void
    {
        Meter::counter('sonder.enrichment.lookups', '{lookup}', 'Metadata lookups, by source, endpoint and outcome')
            ->add(1, ['source' => $source->value, 'endpoint' => $endpoint, 'outcome' => $outcome->value]);
        Meter::histogram('sonder.enrichment.lookup.duration', 's', 'Time one metadata lookup took')
            ->record($seconds, ['source' => $source->value, 'endpoint' => $endpoint]);

        Tracer::activeSpan()->setAttributes([
            'sonder.enrichment.source' => $source->value,
            'sonder.enrichment.endpoint' => $endpoint,
            'sonder.enrichment.outcome' => $outcome->value,
        ]);

        if ($outcome === LookupOutcome::Failed) {
            Log::warning('Metadata lookup failed', ['source' => $source->value, 'endpoint' => $endpoint]);
        }
    }

    public function resolution(ResolutionStatus $status, ?ResolutionMethod $method, ?float $confidence): void
    {
        Meter::counter('sonder.enrichment.resolutions', '{resolution}', 'Provider tracks resolved to recordings, by method and outcome')
            ->add(1, [
                'outcome' => $status->value,
                'method' => $method->value ?? 'none',
                'confidence' => match (true) {
                    $confidence === null => 'none',
                    $confidence >= 0.9 => 'high',
                    default => 'medium',
                },
            ]);
    }

    public function refusal(string $limiter): void
    {
        Meter::counter('sonder.rate_limit.refusals', '{call}', 'Calls Sonder refused itself to stay within a rate limit')
            ->add(1, ['limiter' => $limiter]);
    }

    public function coverage(string $facet, float $ratio): void
    {
        Meter::gauge('sonder.enrichment.coverage', '1', 'Share of the library carrying a kind of metadata')
            ->record($ratio, ['facet' => $facet]);
    }

    public function backlog(string $status, int $count): void
    {
        Meter::gauge('sonder.enrichment.backlog', '{item}', 'Metadata waiting to be fetched again, by status')
            ->record($count, ['status' => $status]);
    }
}
```

```php
// app/Services/Metadata/CallBudget.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Spends one call of a named limiter (defined in AppServiceProvider). Over
 * budget, the call is refused at once rather than waited for: sleeping would
 * hold a queue worker.
 */
final readonly class CallBudget
{
    public const string CREDITS_FM = 'credits-fm';

    public const string MUSICBRAINZ = 'musicbrainz';

    public function __construct(
        private RateLimiter $limiter,
        private EnrichmentTelemetry $telemetry,
    ) {}

    /**
     * @return int|null null when the call is allowed (and counted), else the seconds to wait
     */
    public function spend(string $limiter, string $key = 'global'): ?int
    {
        $limits = $this->limits($limiter, $key);

        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                $this->telemetry->refusal($limiter);

                return max(1, $this->limiter->availableIn($limit->key));
            }
        }

        foreach ($limits as $limit) {
            $this->limiter->hit($limit->key, $limit->decaySeconds);
        }

        return null;
    }

    /**
     * @return list<Limit>
     */
    private function limits(string $name, string $key): array
    {
        $limiter = $this->limiter->limiter($name)
            ?? throw new LogicException("The [{$name}] rate limiter is not defined.");

        return array_values(array_filter(
            Arr::wrap($limiter($key)),
            fn (mixed $limit): bool => $limit instanceof Limit,
        ));
    }
}
```

```php
// tests/Support/FakeEnrichmentTelemetry.php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use App\Services\Metadata\EnrichmentTelemetry;

final class FakeEnrichmentTelemetry implements EnrichmentTelemetry
{
    /** @var list<array{source: string, endpoint: string, outcome: string}> */
    public array $lookups = [];

    /** @var list<array{status: string, method: string|null, confidence: float|null}> */
    public array $resolutions = [];

    /** @var list<string> */
    public array $refusals = [];

    /** @var array<string, float> */
    public array $coverage = [];

    /** @var array<string, int> */
    public array $backlog = [];

    public function lookup(MetadataSource $source, string $endpoint, LookupOutcome $outcome, float $seconds): void
    {
        $this->lookups[] = ['source' => $source->value, 'endpoint' => $endpoint, 'outcome' => $outcome->value];
    }

    public function resolution(ResolutionStatus $status, ?ResolutionMethod $method, ?float $confidence): void
    {
        $this->resolutions[] = ['status' => $status->value, 'method' => $method?->value, 'confidence' => $confidence];
    }

    public function refusal(string $limiter): void
    {
        $this->refusals[] = $limiter;
    }

    public function coverage(string $facet, float $ratio): void
    {
        $this->coverage[$facet] = $ratio;
    }

    public function backlog(string $status, int $count): void
    {
        $this->backlog[$status] = $count;
    }
}
```

In `AppServiceProvider::register()` add:

```php
        $this->app->bind(EnrichmentTelemetry::class, OpenTelemetryEnrichmentTelemetry::class);
```

In `AppServiceProvider::boot()` add, after the YouTube Music limiter:

```php
        // credits.fm documents no limit: stay polite. MusicBrainz allows one
        // request a second per IP and blocks clients that exceed it.
        RateLimiter::for(CallBudget::CREDITS_FM, fn (string $key): array => [
            Limit::perSecond(2)->by('credits-fm:second:'.$key),
            Limit::perHour(3000)->by('credits-fm:hour:'.$key),
        ]);
        RateLimiter::for(CallBudget::MUSICBRAINZ, fn (string $key): array => [
            Limit::perSecond(1)->by('musicbrainz:second:'.$key),
        ]);
```

In `bootstrap/app.php`, next to `ProviderRateLimited`:

```php
        $exceptions->dontReport(MetadataSourceRateLimited::class);
```

In `RateLimitedGateway::spendCall()`, record the refusal before throwing (inject nothing new; resolve lazily to keep the constructor unchanged):

```php
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                resolve(EnrichmentTelemetry::class)->refusal(self::LIMITER);

                throw new ProviderRateLimited(Provider::YouTubeMusic, $this->limiter->availableIn($limit->key));
            }
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Unit/Services/Metadata/CallBudgetTest.php tests/Unit/Services/Music/YouTubeMusic/RateLimitedGatewayTest.php`
Expected: PASS.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Services/Metadata app/Exceptions/Metadata app/Providers/AppServiceProvider.php app/Services/Music/YouTubeMusic/Gateway/RateLimitedGateway.php
git add app bootstrap tests
git commit -m "feat(enrichment): pace metadata calls through named limiters and record telemetry"
```

---

### Task 4: credits.fm gateway and mapper

**Files:**
- Create: `app/Services/Metadata/CreditsFm/{CreditsFmGateway,HttpCreditsFmGateway,RateLimitedCreditsFmGateway,CreditsFmMapper}.php`
- Create: `app/Services/Metadata/Data/{Credit,IsrcDetail}.php`
- Create: `tests/Support/FakeCreditsFmGateway.php`
- Modify: `config/services.php`, `.env.example`, `app/Providers/AppServiceProvider.php` (binding)
- Fixtures (already captured, commit them here): `tests/Fixtures/Metadata/credits-fm-resolve-batch.json`, `tests/Fixtures/Metadata/credits-fm-isrc.json`
- Test: `tests/Unit/Services/Metadata/CreditsFm/HttpCreditsFmGatewayTest.php`, `tests/Unit/Services/Metadata/CreditsFm/CreditsFmMapperTest.php`

**Interfaces:**
- Consumes: `CallBudget`, `EnrichmentTelemetry`, `MetadataSourceRateLimited`, `MetadataSourceUnavailable`, `TrackQuery`.
- Produces:
  - `interface CreditsFmGateway { /** @param list<TrackQuery> $queries @return list<string|null> one ISRC or null per query, same order */ public function resolveBatch(array $queries): array; /** @return array<string, mixed>|null */ public function isrc(string $isrc): ?array; }`
  - `CreditsFmMapper::detail(array $payload): IsrcDetail` and `CreditsFmMapper::credits(array $payload): list<Credit>`
  - `IsrcDetail(string $isrc, string $title, list<string> $artists, ?string $iswc, ?string $releaseDate)`
  - `Credit(string $name, CreditType $type, string $role, ?string $mbid, ?string $ipi, list<string> $attributes)`
  - `FakeCreditsFmGateway` with public `array $isrcs` (keyed `"{artist}|{title}"` → ISRC), `array $details` (ISRC → payload), `?Throwable $failure`, `list<string> $calls`.

- [ ] **Step 1: Configuration**

In `config/services.php` add:

```php
    'credits_fm' => [
        'url' => env('CREDITS_FM_URL', 'https://api.credits.fm'),
    ],

    'musicbrainz' => [
        'url' => env('MUSICBRAINZ_URL', 'https://musicbrainz.org/ws/2'),
        'user_agent' => 'Sonder/1.0 ( '.env('MUSICBRAINZ_CONTACT', 'set MUSICBRAINZ_CONTACT').' )',
    ],
```

In `.env.example` add:

```
CREDITS_FM_URL=https://api.credits.fm
MUSICBRAINZ_URL=https://musicbrainz.org/ws/2
MUSICBRAINZ_CONTACT=
```

Ask the user for the `MUSICBRAINZ_CONTACT` value for their own `.env` (an email or a URL); do not invent one.

- [ ] **Step 2: Write the failing tests**

```php
// tests/Unit/Services/Metadata/CreditsFm/HttpCreditsFmGatewayTest.php
<?php

declare(strict_types=1);

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\CreditsFm\HttpCreditsFmGateway;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEnrichmentTelemetry;

function creditsFmFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/Metadata/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $this->telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $this->telemetry);
});

it('resolves a batch to one isrc per query, in order', function (): void {
    Http::fake(['api.credits.fm/v1/resolve/batch' => Http::response(creditsFmFixture('credits-fm-resolve-batch'))]);

    $isrcs = resolve(HttpCreditsFmGateway::class)->resolveBatch([
        new TrackQuery('Survival', 'Muse'),
        new TrackQuery('Enter Sandman', 'Metallica'),
        new TrackQuery('zzzz nothing here qqq', 'Nobody Atall'),
    ]);

    expect($isrcs)->toBe(['GBAHT1200434', 'GBF089190013', 'USJKL0700030']);
    Http::assertSent(fn (Request $request): bool => $request['contribute'] === false
        && $request['tracks'][0] === ['name' => 'Survival', 'artist' => 'Muse']);
    expect($this->telemetry->lookups)->toBe([['source' => 'credits_fm', 'endpoint' => 'resolve_batch', 'outcome' => 'found']]);
});

it('returns the isrc detail, or null when credits.fm does not know it', function (): void {
    Http::fake([
        'api.credits.fm/v1/isrc/GBAHT1200434*' => Http::response(creditsFmFixture('credits-fm-isrc')),
        'api.credits.fm/v1/isrc/XX0000000000*' => Http::response(['error' => 'not found'], 404),
    ]);
    $gateway = resolve(HttpCreditsFmGateway::class);

    expect($gateway->isrc('GBAHT1200434')['recording_title'] ?? null)->toBe('Survival')
        ->and($gateway->isrc('XX0000000000'))->toBeNull();
});

it('turns a 429 into a rate limit and a 503 into an outage', function (): void {
    Http::fake([
        'api.credits.fm/v1/isrc/AAAAA0000001*' => Http::response('', 429, ['Retry-After' => '30']),
        'api.credits.fm/v1/isrc/AAAAA0000002*' => Http::response('', 503),
    ]);
    $gateway = resolve(HttpCreditsFmGateway::class);

    expect(fn () => $gateway->isrc('AAAAA0000001'))->toThrow(fn (MetadataSourceRateLimited $e) => expect($e->retryAfter)->toBe(30))
        ->and(fn () => $gateway->isrc('AAAAA0000002'))->toThrow(MetadataSourceUnavailable::class);
});
```

```php
// tests/Unit/Services/Metadata/CreditsFm/CreditsFmMapperTest.php
<?php

declare(strict_types=1);

use App\Enums\CreditType;
use App\Services\Metadata\CreditsFm\CreditsFmMapper;
use App\Services\Metadata\Data\Credit;

it('reads the identity of an isrc detail', function (): void {
    $detail = CreditsFmMapper::detail(creditsFmFixture('credits-fm-isrc'));

    expect($detail->isrc)->toBe('GBAHT1200434')
        ->and($detail->title)->toBe('Survival')
        ->and($detail->artists)->toBe(['Muse'])
        ->and($detail->iswc)->toBe('T-912674410-3')
        ->and($detail->releaseDate)->toBe('2012-01-01');
});

it('flattens songwriters, publishers, performers and artists into credits without repeats', function (): void {
    $credits = CreditsFmMapper::credits(creditsFmFixture('credits-fm-isrc'));
    $summary = array_map(fn (Credit $credit): string => "{$credit->type->value}:{$credit->name}:{$credit->role}", $credits);

    expect($summary)->toContain('artist:Muse:')
        ->toContain('songwriter:MATTHEW JAMES BELLAMY:ComposerLyricist')
        ->toContain('publisher:HEWRATE LIMITED:OriginalPublisher')
        ->toContain('producer:Chris Lord‐Alge:mix')
        ->toContain('performer:Matt Bellamy:vocal')
        ->and($summary)->toBe(array_values(array_unique($summary)));

    $publisher = collect($credits)->first(fn (Credit $credit): bool => $credit->name === 'HEWRATE LIMITED');
    $vocal = collect($credits)->first(fn (Credit $credit): bool => $credit->name === 'Matt Bellamy');

    expect($publisher?->ipi)->toBe('00475448521')
        ->and($vocal?->type)->toBe(CreditType::Performer)
        ->and($vocal?->mbid)->toBe('00fc124e-6645-4530-8d0b-7def83c5ee25')
        ->and($vocal?->attributes)->toBe(['lead vocals']);
});
```

Note: `creditsFmFixture()` is declared in the gateway test file; Pest loads every file of the run, but to be safe when the mapper test runs alone, move the helper into `tests/Pest.php` as `function metadataFixture(string $name): array` and use that name in both files (and in later tasks).

- [ ] **Step 3: Run them to verify they fail**

Run: `vendor/bin/pest tests/Unit/Services/Metadata/CreditsFm`
Expected: FAIL, classes not found.

- [ ] **Step 4: Implement**

```php
// app/Services/Metadata/Data/Credit.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

use App\Enums\CreditType;

/**
 * One contribution to a recording as a source states it.
 */
final readonly class Credit
{
    /**
     * @param  string  $role  The source's own wording ("mix", "ComposerLyricist"); '' when it gives none.
     * @param  list<string>  $attributes
     */
    public function __construct(
        public string $name,
        public CreditType $type,
        public string $role,
        public ?string $mbid,
        public ?string $ipi,
        public array $attributes = [],
    ) {}

    public function key(): string
    {
        return implode('|', [$this->type->value, $this->name, $this->role, $this->mbid ?? '', $this->ipi ?? '']);
    }
}
```

```php
// app/Services/Metadata/Data/IsrcDetail.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * What credits.fm knows about one ISRC.
 */
final readonly class IsrcDetail
{
    /**
     * @param  list<string>  $artists
     * @param  string|null  $releaseDate  Y-m-d
     */
    public function __construct(
        public string $isrc,
        public string $title,
        public array $artists,
        public ?string $iswc,
        public ?string $releaseDate,
    ) {}
}
```

```php
// app/Services/Metadata/CreditsFm/CreditsFmGateway.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\CreditsFm;

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Raw calls to credits.fm's public read API.
 */
interface CreditsFmGateway
{
    /**
     * At most 50 queries. credits.fm answers even nonsense with an ISRC:
     * callers must verify it.
     *
     * @param  list<TrackQuery>  $queries
     * @return list<string|null> one ISRC (or null) per query, in order
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function resolveBatch(array $queries): array;

    /**
     * @return array<string, mixed>|null null when credits.fm does not know the ISRC
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function isrc(string $isrc): ?array;
}
```

```php
// app/Services/Metadata/CreditsFm/HttpCreditsFmGateway.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\CreditsFm;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class HttpCreditsFmGateway implements CreditsFmGateway
{
    public function __construct(private EnrichmentTelemetry $telemetry) {}

    public function resolveBatch(array $queries): array
    {
        $response = $this->send('resolve_batch', fn (PendingRequest $http): Response => $http->post('/v1/resolve/batch', [
            'tracks' => array_map(fn (TrackQuery $query): array => ['name' => $query->title, 'artist' => $query->artist], $queries),
            'contribute' => false,
        ]));

        $results = $response?->json('results');

        if (! is_array($results)) {
            throw new MetadataSourceUnavailable(MetadataSource::CreditsFm, 'resolve/batch returned no results');
        }

        return array_map(
            fn (int $index): ?string => is_string($results[$index]['isrc'] ?? null) ? $results[$index]['isrc'] : null,
            array_keys($queries),
        );
    }

    public function isrc(string $isrc): ?array
    {
        $response = $this->send('isrc', fn (PendingRequest $http): Response => $http->get('/v1/isrc/'.rawurlencode($isrc), ['contribute' => 'false']));

        /** @var array<string, mixed>|null */
        return $response?->json();
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return Response|null null on 404
     */
    private function send(string $endpoint, callable $call): ?Response
    {
        $started = microtime(true);

        try {
            $response = $call(Http::baseUrl((string) config('services.credits_fm.url'))->acceptJson()->connectTimeout(5)->timeout(30));
        } catch (ConnectionException $exception) {
            $this->telemetry->lookup(MetadataSource::CreditsFm, $endpoint, LookupOutcome::Failed, microtime(true) - $started);

            throw new MetadataSourceUnavailable(MetadataSource::CreditsFm, $exception->getMessage());
        }

        $seconds = microtime(true) - $started;

        if ($response->status() === 429) {
            throw new MetadataSourceRateLimited(MetadataSource::CreditsFm, max(1, (int) $response->header('Retry-After') ?: 60));
        }

        if ($response->status() === 404) {
            $this->telemetry->lookup(MetadataSource::CreditsFm, $endpoint, LookupOutcome::NotFound, $seconds);

            return null;
        }

        if (! $response->successful()) {
            $this->telemetry->lookup(MetadataSource::CreditsFm, $endpoint, LookupOutcome::Failed, $seconds);

            throw new MetadataSourceUnavailable(MetadataSource::CreditsFm, "HTTP {$response->status()} on {$endpoint}");
        }

        $this->telemetry->lookup(MetadataSource::CreditsFm, $endpoint, LookupOutcome::Found, $seconds);

        return $response;
    }
}
```

```php
// app/Services/Metadata/CreditsFm/RateLimitedCreditsFmGateway.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\CreditsFm;

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\CallBudget;

/**
 * Spends the `credits-fm` budget before every call.
 */
final readonly class RateLimitedCreditsFmGateway implements CreditsFmGateway
{
    public function __construct(
        private CreditsFmGateway $gateway,
        private CallBudget $budget,
    ) {}

    public function resolveBatch(array $queries): array
    {
        $this->spend();

        return $this->gateway->resolveBatch($queries);
    }

    public function isrc(string $isrc): ?array
    {
        $this->spend();

        return $this->gateway->isrc($isrc);
    }

    private function spend(): void
    {
        $wait = $this->budget->spend(CallBudget::CREDITS_FM);

        if ($wait !== null) {
            throw new MetadataSourceRateLimited(MetadataSource::CreditsFm, $wait);
        }
    }
}
```

```php
// app/Services/Metadata/CreditsFm/CreditsFmMapper.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\CreditsFm;

use App\Enums\CreditType;
use App\Services\Metadata\Data\Credit;
use App\Services\Metadata\Data\IsrcDetail;

/**
 * Turns credits.fm payloads into Data objects. Pure.
 */
final class CreditsFmMapper
{
    /**
     * @param  array<string, mixed>  $payload  a `GET /v1/isrc/{isrc}` answer
     */
    public static function detail(array $payload): IsrcDetail
    {
        return new IsrcDetail(
            isrc: self::string($payload['isrc'] ?? null) ?? '',
            title: self::string($payload['recording_title'] ?? null) ?? '',
            artists: array_values(array_filter(array_map(self::string(...), (array) ($payload['artist_names'] ?? [])))),
            iswc: self::string($payload['iswc'] ?? null),
            releaseDate: self::string($payload['release_date'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $payload  a `GET /v1/isrc/{isrc}` answer
     * @return list<Credit>
     */
    public static function credits(array $payload): array
    {
        $credits = [];

        foreach ((array) ($payload['recording_artists'] ?? []) as $artist) {
            $credits[] = new Credit(self::string($artist['name'] ?? null) ?? '', CreditType::Artist, '', self::string($artist['mbid'] ?? null), null);
        }

        foreach ((array) ($payload['songwriters'] ?? []) as $writer) {
            $credits[] = new Credit(self::string($writer['name'] ?? null) ?? '', CreditType::Songwriter, self::string($writer['role'] ?? null) ?? '', null, self::string($writer['ipi'] ?? null));

            foreach ((array) ($writer['publishers'] ?? []) as $publisher) {
                $credits[] = new Credit(self::string($publisher['name'] ?? null) ?? '', CreditType::Publisher, self::string($publisher['role'] ?? null) ?? '', null, self::string($publisher['ipi'] ?? null));
            }
        }

        foreach ((array) ($payload['performers'] ?? []) as $performer) {
            $credits[] = new Credit(
                name: self::string($performer['name'] ?? null) ?? '',
                type: ($performer['credit_type'] ?? null) === 'producer' ? CreditType::Producer : CreditType::Performer,
                role: self::string($performer['role'] ?? null) ?? '',
                mbid: self::string($performer['mbid'] ?? null),
                ipi: null,
                attributes: array_values(array_filter(array_map(self::string(...), (array) ($performer['attributes'] ?? [])))),
            );
        }

        $unique = [];

        foreach ($credits as $credit) {
            if ($credit->name !== '') {
                $unique[$credit->key()] ??= $credit;
            }
        }

        return array_values($unique);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
```

```php
// tests/Support/FakeCreditsFmGateway.php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\Data\TrackQuery;
use Throwable;

final class FakeCreditsFmGateway implements CreditsFmGateway
{
    /** @var array<string, string> "artist|title" => ISRC */
    public array $isrcs = [];

    /** @var array<string, array<string, mixed>> ISRC => payload */
    public array $details = [];

    public ?Throwable $failure = null;

    /** @var list<string> */
    public array $calls = [];

    public function resolveBatch(array $queries): array
    {
        $this->calls[] = 'resolve_batch';
        $this->fail();

        return array_map(fn (TrackQuery $query): ?string => $this->isrcs["{$query->artist}|{$query->title}"] ?? null, $queries);
    }

    public function isrc(string $isrc): ?array
    {
        $this->calls[] = "isrc:{$isrc}";
        $this->fail();

        return $this->details[$isrc] ?? null;
    }

    private function fail(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
```

In `AppServiceProvider::register()`:

```php
        $this->app->bind(CreditsFmGateway::class, fn (): CreditsFmGateway => new RateLimitedCreditsFmGateway(
            $this->app->make(HttpCreditsFmGateway::class),
            $this->app->make(CallBudget::class),
        ));
```

Add to `tests/Pest.php`:

```php
/**
 * @return array<string, mixed>
 */
function metadataFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__."/Fixtures/Metadata/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
}
```

and replace `creditsFmFixture(` with `metadataFixture(` in both test files (delete the local helper).

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Unit/Services/Metadata/CreditsFm`
Expected: PASS.

- [ ] **Step 6: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Services/Metadata app/Providers/AppServiceProvider.php config/services.php
git add app config .env.example tests
git commit -m "feat(enrichment): read isrcs and credits from credits.fm"
```

---

### Task 5: MusicBrainz gateway and mapper

**Files:**
- Create: `app/Services/Metadata/MusicBrainz/{MusicBrainzGateway,HttpMusicBrainzGateway,RateLimitedMusicBrainzGateway,MusicBrainzMapper}.php`
- Create: `app/Services/Metadata/Data/{RegistryRecording,WeightedTag}.php`
- Create: `tests/Support/FakeMusicBrainzGateway.php`
- Modify: `app/Providers/AppServiceProvider.php` (binding)
- Fixtures (already captured): `musicbrainz-isrc.json`, `musicbrainz-isrc-mismatch.json`, `musicbrainz-recording.json`, `musicbrainz-recording-search.json`, `musicbrainz-artist.json`
- Test: `tests/Unit/Services/Metadata/MusicBrainz/HttpMusicBrainzGatewayTest.php`, `tests/Unit/Services/Metadata/MusicBrainz/MusicBrainzMapperTest.php`

**Interfaces:**
- Produces:
  - `interface MusicBrainzGateway { /** @return array<string, mixed>|null */ public function isrc(string $isrc): ?array; /** @return array<string, mixed>|null */ public function recording(string $mbid): ?array; /** @return array<string, mixed> */ public function searchRecordings(TrackQuery $query): array; /** @return array<string, mixed>|null */ public function artist(string $mbid): ?array; }`
  - `RegistryRecording(string $mbid, string $title, ?int $durationSeconds, list<array{mbid: string, name: string}> $artists, list<string> $isrcs, ?int $score, ?string $firstReleaseDate)`
  - `WeightedTag(string $name, int $weight, bool $isGenre)`
  - `MusicBrainzMapper::recordings(array $payload): list<RegistryRecording>` (works for both `isrc` and search payloads), `MusicBrainzMapper::recording(array $payload): RegistryRecording`, `MusicBrainzMapper::tags(array $payload): list<WeightedTag>` (works for recording and artist payloads)
  - `FakeMusicBrainzGateway` with public `array $isrcs` (ISRC → payload), `array $recordings` (MBID → payload), `array $searches` ("artist|title" → payload), `array $artists` (MBID → payload), `?Throwable $failure`, `list<string> $calls`.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Unit/Services/Metadata/MusicBrainz/HttpMusicBrainzGatewayTest.php
<?php

declare(strict_types=1);

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\MusicBrainz\HttpMusicBrainzGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEnrichmentTelemetry;

beforeEach(function (): void {
    app()->instance(EnrichmentTelemetry::class, new FakeEnrichmentTelemetry());
    config(['services.musicbrainz.user_agent' => 'Sonder/1.0 ( test@example.test )']);
});

it('looks an isrc up with its artists and identifies itself', function (): void {
    Http::fake(['musicbrainz.org/ws/2/isrc/GBAHT1200434*' => Http::response(metadataFixture('musicbrainz-isrc'))]);

    $payload = resolve(HttpMusicBrainzGateway::class)->isrc('GBAHT1200434');

    expect($payload['recordings'][0]['id'] ?? null)->toBe('464d783d-1be7-4e1c-a75b-2b568eb20454');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'Sonder/1.0 ( test@example.test )')
        && $request['inc'] === 'artist-credits'
        && $request['fmt'] === 'json');
});

it('returns null for an isrc MusicBrainz does not know', function (): void {
    Http::fake(['musicbrainz.org/ws/2/isrc/*' => Http::response(metadataFixture('musicbrainz-isrc-mismatch'), 404)]);

    expect(resolve(HttpMusicBrainzGateway::class)->isrc('USJKL0700030'))->toBeNull();
});

it('asks for genres and tags on a recording and searches by title and artist', function (): void {
    Http::fake([
        'musicbrainz.org/ws/2/recording/464d783d*' => Http::response(metadataFixture('musicbrainz-recording')),
        'musicbrainz.org/ws/2/recording?*' => Http::response(metadataFixture('musicbrainz-recording-search')),
    ]);
    $gateway = resolve(HttpMusicBrainzGateway::class);

    $gateway->recording('464d783d-1be7-4e1c-a75b-2b568eb20454');
    $gateway->searchRecordings(new TrackQuery('Survival', 'Muse'));

    Http::assertSent(fn (Request $request): bool => ($request['inc'] ?? null) === 'genres+tags+artist-credits+isrcs');
    Http::assertSent(fn (Request $request): bool => ($request['query'] ?? null) === 'recording:"Survival" AND artist:"Muse"');
});

it('treats a busy server as a rate limit', function (): void {
    Http::fake(['musicbrainz.org/*' => Http::response(['error' => 'busy'], 503)]);

    expect(fn () => resolve(HttpMusicBrainzGateway::class)->artist('9c9f1380-2516-4fc9-a3e6-f9f61941d090'))
        ->toThrow(MetadataSourceRateLimited::class);
});
```

```php
// tests/Unit/Services/Metadata/MusicBrainz/MusicBrainzMapperTest.php
<?php

declare(strict_types=1);

use App\Services\Metadata\Data\WeightedTag;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;

it('reads the recordings of an isrc with their length in seconds', function (): void {
    $recordings = MusicBrainzMapper::recordings(metadataFixture('musicbrainz-isrc'));

    expect($recordings)->toHaveCount(1)
        ->and($recordings[0]->mbid)->toBe('464d783d-1be7-4e1c-a75b-2b568eb20454')
        ->and($recordings[0]->durationSeconds)->toBe(257)
        ->and($recordings[0]->artists)->toBe([['mbid' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090', 'name' => 'Muse']]);
});

it('keeps the search score', function (): void {
    $recordings = MusicBrainzMapper::recordings(metadataFixture('musicbrainz-recording-search'));

    expect($recordings[0]->score)->toBe(100);
});

it('reads a recording with its isrcs and first release date', function (): void {
    $recording = MusicBrainzMapper::recording(metadataFixture('musicbrainz-recording'));

    expect($recording->isrcs)->toContain('GBAHT1200434')
        ->and($recording->firstReleaseDate)->not->toBeNull();
});

it('weighs tags against the most voted one and marks genres', function (): void {
    $tags = MusicBrainzMapper::tags(metadataFixture('musicbrainz-artist'));
    $byName = collect($tags)->keyBy(fn (WeightedTag $tag): string => $tag->name);

    expect($byName['alternative rock']->weight)->toBe(100)
        ->and($byName['alternative rock']->isGenre)->toBeTrue()
        ->and($byName['alternative dance']->weight)->toBe((int) round(2 / 32 * 100))
        ->and($tags)->toHaveCount(count(array_unique(array_map(fn (WeightedTag $tag): string => $tag->name, $tags))));
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Unit/Services/Metadata/MusicBrainz`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

```php
// app/Services/Metadata/Data/RegistryRecording.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * A recording as MusicBrainz describes it.
 */
final readonly class RegistryRecording
{
    /**
     * @param  list<array{mbid: string, name: string}>  $artists
     * @param  list<string>  $isrcs
     * @param  int|null  $score  0–100, search results only
     */
    public function __construct(
        public string $mbid,
        public string $title,
        public ?int $durationSeconds,
        public array $artists,
        public array $isrcs = [],
        public ?int $score = null,
        public ?string $firstReleaseDate = null,
    ) {}
}
```

```php
// app/Services/Metadata/Data/WeightedTag.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

/**
 * A tag with its weight (0–100) within one source's answer.
 */
final readonly class WeightedTag
{
    public function __construct(
        public string $name,
        public int $weight,
        public bool $isGenre,
    ) {}
}
```

```php
// app/Services/Metadata/MusicBrainz/MusicBrainzGateway.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\MusicBrainz;

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Raw calls to the MusicBrainz web service (ws/2, JSON).
 *
 * @throws MetadataSourceRateLimited
 * @throws MetadataSourceUnavailable
 */
interface MusicBrainzGateway
{
    /**
     * Recordings carrying this ISRC, with their artists. Null when unknown.
     *
     * @return array<string, mixed>|null
     */
    public function isrc(string $isrc): ?array;

    /**
     * One recording with genres, tags, artists and ISRCs. Null when unknown.
     *
     * @return array<string, mixed>|null
     */
    public function recording(string $mbid): ?array;

    /**
     * @return array<string, mixed>
     */
    public function searchRecordings(TrackQuery $query): array;

    /**
     * One artist with genres and tags. Null when unknown.
     *
     * @return array<string, mixed>|null
     */
    public function artist(string $mbid): ?array;
}
```

```php
// app/Services/Metadata/MusicBrainz/HttpMusicBrainzGateway.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\MusicBrainz;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final readonly class HttpMusicBrainzGateway implements MusicBrainzGateway
{
    public function __construct(private EnrichmentTelemetry $telemetry) {}

    public function isrc(string $isrc): ?array
    {
        return $this->get('isrc', '/isrc/'.rawurlencode($isrc), ['inc' => 'artist-credits']);
    }

    public function recording(string $mbid): ?array
    {
        return $this->get('recording', '/recording/'.rawurlencode($mbid), ['inc' => 'genres+tags+artist-credits+isrcs']);
    }

    public function searchRecordings(TrackQuery $query): array
    {
        $escape = fn (string $text): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $text);

        return $this->get('search', '/recording', [
            'query' => 'recording:"'.$escape($query->title).'" AND artist:"'.$escape($query->artist).'"',
            'limit' => 5,
        ]) ?? ['recordings' => []];
    }

    public function artist(string $mbid): ?array
    {
        return $this->get('artist', '/artist/'.rawurlencode($mbid), ['inc' => 'genres+tags']);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null
     */
    private function get(string $endpoint, string $path, array $query): ?array
    {
        $started = microtime(true);

        try {
            $response = Http::baseUrl((string) config('services.musicbrainz.url'))
                ->withUserAgent((string) config('services.musicbrainz.user_agent'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->get($path, [...$query, 'fmt' => 'json']);
        } catch (ConnectionException $exception) {
            $this->telemetry->lookup(MetadataSource::MusicBrainz, $endpoint, LookupOutcome::Failed, microtime(true) - $started);

            throw new MetadataSourceUnavailable(MetadataSource::MusicBrainz, $exception->getMessage());
        }

        $seconds = microtime(true) - $started;

        // MusicBrainz answers 503 when a client goes over its rate limit.
        if (in_array($response->status(), [429, 503], true)) {
            throw new MetadataSourceRateLimited(MetadataSource::MusicBrainz, max(1, (int) $response->header('Retry-After') ?: 5));
        }

        if ($response->status() === 404) {
            $this->telemetry->lookup(MetadataSource::MusicBrainz, $endpoint, LookupOutcome::NotFound, $seconds);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            $this->telemetry->lookup(MetadataSource::MusicBrainz, $endpoint, LookupOutcome::Failed, $seconds);

            throw new MetadataSourceUnavailable(MetadataSource::MusicBrainz, "HTTP {$response->status()} on {$endpoint}");
        }

        $this->telemetry->lookup(MetadataSource::MusicBrainz, $endpoint, LookupOutcome::Found, $seconds);

        /** @var array<string, mixed> */
        return $response->json();
    }
}
```

```php
// app/Services/Metadata/MusicBrainz/RateLimitedMusicBrainzGateway.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\MusicBrainz;

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\CallBudget;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Spends the `musicbrainz` budget (one call a second) before every call.
 */
final readonly class RateLimitedMusicBrainzGateway implements MusicBrainzGateway
{
    public function __construct(
        private MusicBrainzGateway $gateway,
        private CallBudget $budget,
    ) {}

    public function isrc(string $isrc): ?array
    {
        $this->spend();

        return $this->gateway->isrc($isrc);
    }

    public function recording(string $mbid): ?array
    {
        $this->spend();

        return $this->gateway->recording($mbid);
    }

    public function searchRecordings(TrackQuery $query): array
    {
        $this->spend();

        return $this->gateway->searchRecordings($query);
    }

    public function artist(string $mbid): ?array
    {
        $this->spend();

        return $this->gateway->artist($mbid);
    }

    private function spend(): void
    {
        $wait = $this->budget->spend(CallBudget::MUSICBRAINZ);

        if ($wait !== null) {
            throw new MetadataSourceRateLimited(MetadataSource::MusicBrainz, $wait);
        }
    }
}
```

```php
// app/Services/Metadata/MusicBrainz/MusicBrainzMapper.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\MusicBrainz;

use App\Services\Metadata\Data\RegistryRecording;
use App\Services\Metadata\Data\WeightedTag;

/**
 * Turns MusicBrainz payloads into Data objects. Pure.
 */
final class MusicBrainzMapper
{
    /**
     * @param  array<string, mixed>  $payload  an `isrc` lookup or a recording search
     * @return list<RegistryRecording>
     */
    public static function recordings(array $payload): array
    {
        return array_values(array_map(
            fn (array $recording): RegistryRecording => self::recording($recording),
            array_filter((array) ($payload['recordings'] ?? []), is_array(...)),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function recording(array $payload): RegistryRecording
    {
        $length = $payload['length'] ?? null;

        return new RegistryRecording(
            mbid: (string) ($payload['id'] ?? ''),
            title: (string) ($payload['title'] ?? ''),
            durationSeconds: is_int($length) ? intdiv($length + 500, 1000) : null,
            artists: array_values(array_map(
                fn (array $credit): array => ['mbid' => (string) ($credit['artist']['id'] ?? ''), 'name' => (string) ($credit['artist']['name'] ?? $credit['name'] ?? '')],
                array_filter((array) ($payload['artist-credit'] ?? []), is_array(...)),
            )),
            isrcs: array_values(array_filter((array) ($payload['isrcs'] ?? []), is_string(...))),
            score: isset($payload['score']) ? (int) $payload['score'] : null,
            firstReleaseDate: is_string($payload['first-release-date'] ?? null) && $payload['first-release-date'] !== '' ? $payload['first-release-date'] : null,
        );
    }

    /**
     * Genres and tags, merged by name, weighted by votes against the most
     * voted one.
     *
     * @param  array<string, mixed>  $payload  a recording or artist lookup
     * @return list<WeightedTag>
     */
    public static function tags(array $payload): array
    {
        $genres = [];
        $counts = [];

        foreach ((array) ($payload['genres'] ?? []) as $genre) {
            $name = mb_strtolower(trim((string) ($genre['name'] ?? '')));
            $genres[$name] = true;
            $counts[$name] = max($counts[$name] ?? 0, (int) ($genre['count'] ?? 0));
        }

        foreach ((array) ($payload['tags'] ?? []) as $tag) {
            $name = mb_strtolower(trim((string) ($tag['name'] ?? '')));
            $counts[$name] = max($counts[$name] ?? 0, (int) ($tag['count'] ?? 0));
        }

        unset($counts['']);
        $top = max([1, ...array_values($counts)]);

        return array_values(array_map(
            fn (string $name, int $count): WeightedTag => new WeightedTag($name, (int) round(max(0, $count) / $top * 100), isset($genres[$name])),
            array_keys($counts),
            array_values($counts),
        ));
    }
}
```

`(int) round(...)` with `count` 0 gives weight 0; keep those rows (a tag present with no votes still describes the recording).

```php
// tests/Support/FakeMusicBrainzGateway.php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Throwable;

final class FakeMusicBrainzGateway implements MusicBrainzGateway
{
    /** @var array<string, array<string, mixed>> */
    public array $isrcs = [];

    /** @var array<string, array<string, mixed>> */
    public array $recordings = [];

    /** @var array<string, array<string, mixed>> "artist|title" => payload */
    public array $searches = [];

    /** @var array<string, array<string, mixed>> */
    public array $artists = [];

    public ?Throwable $failure = null;

    /** @var list<string> */
    public array $calls = [];

    public function isrc(string $isrc): ?array
    {
        return $this->answer("isrc:{$isrc}", $this->isrcs[$isrc] ?? null);
    }

    public function recording(string $mbid): ?array
    {
        return $this->answer("recording:{$mbid}", $this->recordings[$mbid] ?? null);
    }

    public function searchRecordings(TrackQuery $query): array
    {
        return $this->answer("search:{$query->artist}|{$query->title}", $this->searches["{$query->artist}|{$query->title}"] ?? ['recordings' => []]) ?? [];
    }

    public function artist(string $mbid): ?array
    {
        return $this->answer("artist:{$mbid}", $this->artists[$mbid] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function answer(string $call, ?array $payload): ?array
    {
        $this->calls[] = $call;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $payload;
    }
}
```

In `AppServiceProvider::register()`:

```php
        $this->app->bind(MusicBrainzGateway::class, fn (): MusicBrainzGateway => new RateLimitedMusicBrainzGateway(
            $this->app->make(HttpMusicBrainzGateway::class),
            $this->app->make(CallBudget::class),
        ));
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Unit/Services/Metadata`
Expected: PASS.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Services/Metadata app/Providers/AppServiceProvider.php
git add app tests
git commit -m "feat(enrichment): read recordings, genres and tags from MusicBrainz"
```

---

### Task 6: Resolve provider tracks to recordings

**Files:**
- Create: `app/Actions/ResolveRecordings.php`, `app/Services/Metadata/Data/TrackToResolve.php`
- Test: `tests/Unit/Actions/ResolveRecordingsTest.php`

**Interfaces:**
- Consumes: `CreditsFmGateway`, `MusicBrainzGateway`, `CreditsFmMapper::detail`, `MusicBrainzMapper::recordings`, `MusicText`, `Enrichment::store`, `EnrichmentTelemetry::resolution`.
- Produces: `TrackToResolve(Provider $provider, string $externalId, string $title, string $artists, ?int $durationSeconds)` with `key(): string` returning `"{$provider->value}:{$externalId}"`; `ResolveRecordings::handle(list<TrackToResolve> $tracks): list<Recording>` (the recordings resolved in this run, unique). At most 25 tracks per call (two readings each fit one 50-query batch).

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Actions/ResolveRecordingsTest.php
<?php

declare(strict_types=1);

use App\Actions\ResolveRecordings;
use App\Enums\Provider;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\Data\TrackToResolve;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Tests\Support\FakeCreditsFmGateway;
use Tests\Support\FakeEnrichmentTelemetry;
use Tests\Support\FakeMusicBrainzGateway;

beforeEach(function (): void {
    $this->creditsFm = new FakeCreditsFmGateway();
    $this->musicBrainz = new FakeMusicBrainzGateway();
    $this->telemetry = new FakeEnrichmentTelemetry();
    app()->instance(CreditsFmGateway::class, $this->creditsFm);
    app()->instance(MusicBrainzGateway::class, $this->musicBrainz);
    app()->instance(EnrichmentTelemetry::class, $this->telemetry);
});

function survival(?int $duration = 258, string $title = 'Survival (Official Video)', string $artists = 'Muse'): TrackToResolve
{
    return new TrackToResolve(Provider::YouTubeMusic, 'UcOUJM08bYk', $title, $artists, $duration);
}

it('resolves through a credits.fm isrc that MusicBrainz confirms', function (): void {
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    $recordings = resolve(ResolveRecordings::class)->handle([survival()]);

    expect($recordings)->toHaveCount(1)
        ->and($recordings[0]->mbid)->toBe('464d783d-1be7-4e1c-a75b-2b568eb20454')
        ->and($recordings[0]->isrc)->toBe('GBAHT1200434')
        ->and($recordings[0]->duration_seconds)->toBe(257);

    $resolution = RecordingResolution::query()->sole();
    expect($resolution->status)->toBe(ResolutionStatus::Resolved)
        ->and($resolution->method)->toBe(ResolutionMethod::CreditsFm)
        ->and($resolution->confidence)->toBe(1.0)
        ->and($resolution->recording_id)->toBe($recordings[0]->id)
        ->and($resolution->next_attempt_at?->toIso8601String())->toBe(now()->addDays(90)->toIso8601String());
});

it('rejects a MusicBrainz recording whose length is too far from the source', function (): void {
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    resolve(ResolveRecordings::class)->handle([survival(duration: 300)]);

    expect(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::NotFound);
});

it('keeps an isrc MusicBrainz lacks when credits.fm details match', function (): void {
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->creditsFm->details['GBAHT1200434'] = metadataFixture('credits-fm-isrc');

    $recordings = resolve(ResolveRecordings::class)->handle([survival()]);

    expect($recordings[0]->mbid)->toBeNull()
        ->and($recordings[0]->isrc)->toBe('GBAHT1200434')
        ->and(RecordingResolution::query()->sole()->confidence)->toBe(0.6);
});

it('rejects an isrc credits.fm made up', function (): void {
    $this->creditsFm->isrcs['Nobody Atall|zzzz nothing here qqq'] = 'USJKL0700030';
    $this->creditsFm->details['USJKL0700030'] = ['isrc' => 'USJKL0700030', 'recording_title' => 'Something Else', 'artist_names' => ['Someone']];

    $recordings = resolve(ResolveRecordings::class)->handle([
        new TrackToResolve(Provider::YouTubeMusic, 'aaaaaaaaaaa', 'zzzz nothing here qqq', 'Nobody Atall', 200),
    ]);

    expect($recordings)->toBe([])
        ->and(Recording::query()->count())->toBe(0)
        ->and(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::NotFound)
        ->and(RecordingResolution::query()->sole()->next_attempt_at?->toIso8601String())->toBe(now()->addDays(30)->toIso8601String());
});

it('falls back to a confident MusicBrainz search', function (): void {
    $this->musicBrainz->searches['Muse|Survival'] = metadataFixture('musicbrainz-recording-search');

    $recordings = resolve(ResolveRecordings::class)->handle([survival(duration: 257)]);

    expect($recordings[0]->mbid)->toBe('99a92728-88ba-4cae-a6fa-39a66d07fc03')
        ->and(RecordingResolution::query()->sole()->method)->toBe(ResolutionMethod::MusicBrainzSearch)
        ->and(RecordingResolution::query()->sole()->confidence)->toBe(0.9);
});

it('accepts a search hit without a duration only at full score', function (): void {
    $search = metadataFixture('musicbrainz-recording-search');
    $search['recordings'] = array_map(fn (array $recording): array => [...$recording, 'score' => 95], $search['recordings']);
    $this->musicBrainz->searches['Muse|Survival'] = $search;

    resolve(ResolveRecordings::class)->handle([survival(duration: null)]);

    expect(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::NotFound);
});

it('tries the title both ways', function (): void {
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    $recordings = resolve(ResolveRecordings::class)->handle([survival(title: 'Muse - Survival', artists: 'Some Fan Channel')]);

    expect($recordings)->toHaveCount(1)
        ->and(RecordingResolution::query()->sole()->query_artist)->toBe('Muse');
});

it('reuses the recording another source already resolved to', function (): void {
    $existing = Recording::factory()->create(['mbid' => '464d783d-1be7-4e1c-a75b-2b568eb20454', 'isrc' => null]);
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    $recordings = resolve(ResolveRecordings::class)->handle([survival()]);

    expect($recordings[0]->id)->toBe($existing->id)
        ->and($recordings[0]->isrc)->toBe('GBAHT1200434')
        ->and(Recording::query()->count())->toBe(1);
});

it('reports each resolution', function (): void {
    resolve(ResolveRecordings::class)->handle([survival()]);

    expect($this->telemetry->resolutions)->toBe([['status' => 'not_found', 'method' => null, 'confidence' => null]]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/Actions/ResolveRecordingsTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

```php
// app/Services/Metadata/Data/TrackToResolve.php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\Data;

use App\Enums\Provider;

/**
 * A provider's track as the library shows it, to be matched to a recording.
 */
final readonly class TrackToResolve
{
    public function __construct(
        public Provider $provider,
        public string $externalId,
        public string $title,
        public string $artists,
        public ?int $durationSeconds,
    ) {}

    public function key(): string
    {
        return "{$this->provider->value}:{$this->externalId}";
    }
}
```

```php
// app/Actions/ResolveRecordings.php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\CreditsFm\CreditsFmMapper;
use App\Services\Metadata\Data\RegistryRecording;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\Data\TrackToResolve;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;
use App\Support\MusicText;

final readonly class ResolveRecordings
{
    public const int MAX_TRACKS = 25;

    private const int DURATION_TOLERANCE_SECONDS = 5;

    public function __construct(
        private CreditsFmGateway $creditsFm,
        private MusicBrainzGateway $musicBrainz,
        private EnrichmentTelemetry $telemetry,
    ) {}

    /**
     * Matches provider tracks to registry recordings and records every
     * outcome. Rate limits propagate so the caller can retry the batch;
     * tracks already resolved by then keep their resolution.
     *
     * @param  list<TrackToResolve>  $tracks  at most MAX_TRACKS
     * @return list<Recording>
     */
    public function handle(array $tracks): array
    {
        $readings = [];

        foreach ($tracks as $index => $track) {
            foreach (MusicText::queries($track->title, $track->artists) as $query) {
                $readings[] = ['track' => $index, 'query' => $query];
            }
        }

        $isrcs = $this->creditsFm->resolveBatch(array_column($readings, 'query'));
        $resolved = [];

        foreach ($tracks as $index => $track) {
            $candidates = [];

            foreach ($readings as $position => $reading) {
                if ($reading['track'] === $index) {
                    $candidates[] = ['query' => $reading['query'], 'isrc' => $isrcs[$position] ?? null];
                }
            }

            $recording = $this->resolve($track, $candidates);

            if ($recording !== null) {
                $resolved[$recording->id] = $recording;
            }
        }

        return array_values($resolved);
    }

    /**
     * @param  list<array{query: TrackQuery, isrc: string|null}>  $candidates
     */
    private function resolve(TrackToResolve $track, array $candidates): ?Recording
    {
        foreach ($candidates as $candidate) {
            if ($candidate['isrc'] === null) {
                continue;
            }

            $confirmed = $this->confirmedByMusicBrainz($track, $candidate['query'], $candidate['isrc']);

            if ($confirmed !== null) {
                return $this->record($track, $candidate['query'], ResolutionMethod::CreditsFm, 1.0, $confirmed, $candidate['isrc']);
            }

            if ($this->confirmedByCreditsFm($track, $candidate['query'], $candidate['isrc'])) {
                return $this->record($track, $candidate['query'], ResolutionMethod::CreditsFm, 0.6, null, $candidate['isrc']);
            }
        }

        foreach ($candidates as $candidate) {
            $found = $this->searchMusicBrainz($track, $candidate['query']);

            if ($found !== null) {
                return $this->record($track, $candidate['query'], ResolutionMethod::MusicBrainzSearch, 0.9, $found, $found->isrcs[0] ?? null);
            }
        }

        $this->recordMiss($track, $candidates[0]['query']);

        return null;
    }

    private function confirmedByMusicBrainz(TrackToResolve $track, TrackQuery $query, string $isrc): ?RegistryRecording
    {
        $payload = $this->musicBrainz->isrc($isrc);
        Enrichment::store(Enrichment::SOURCE, $track->key(), MetadataSource::MusicBrainz, "isrc:{$isrc}", $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done, $payload);

        if ($payload === null) {
            return null;
        }

        foreach (MusicBrainzMapper::recordings($payload) as $recording) {
            if ($this->sameDuration($track->durationSeconds, $recording->durationSeconds) && $this->creditsArtist($recording, $query->artist)) {
                return $recording;
            }
        }

        return null;
    }

    private function confirmedByCreditsFm(TrackToResolve $track, TrackQuery $query, string $isrc): bool
    {
        $payload = $this->creditsFm->isrc($isrc);
        Enrichment::store(Enrichment::SOURCE, $track->key(), MetadataSource::CreditsFm, "isrc:{$isrc}", $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done, $payload);

        if ($payload === null) {
            return false;
        }

        $detail = CreditsFmMapper::detail($payload);

        return MusicText::sameTitle($detail->title, $query->title)
            && array_any($detail->artists, fn (string $artist): bool => MusicText::sameArtist($artist, $query->artist));
    }

    private function searchMusicBrainz(TrackToResolve $track, TrackQuery $query): ?RegistryRecording
    {
        $payload = $this->musicBrainz->searchRecordings($query);
        Enrichment::store(Enrichment::SOURCE, $track->key(), MetadataSource::MusicBrainz, 'search', EnrichmentStatus::Done, $payload);

        foreach (MusicBrainzMapper::recordings($payload) as $recording) {
            $durationOk = $track->durationSeconds === null
                ? $recording->score === 100
                : ($recording->score ?? 0) >= 90 && $this->sameDuration($track->durationSeconds, $recording->durationSeconds);

            if ($durationOk && $this->creditsArtist($recording, $query->artist) && MusicText::sameTitle($recording->title, $query->title)) {
                return $recording;
            }
        }

        return null;
    }

    private function sameDuration(?int $source, ?int $registry): bool
    {
        return $source === null || $registry === null || abs($source - $registry) <= self::DURATION_TOLERANCE_SECONDS;
    }

    private function creditsArtist(RegistryRecording $recording, string $artist): bool
    {
        return array_any($recording->artists, fn (array $credited): bool => MusicText::sameArtist($credited['name'], $artist));
    }

    private function record(TrackToResolve $track, TrackQuery $query, ResolutionMethod $method, float $confidence, ?RegistryRecording $registry, ?string $isrc): Recording
    {
        $recording = $this->recordingFor($registry, $isrc, $query, $track->durationSeconds);

        RecordingResolution::query()->updateOrCreate(
            ['provider' => $track->provider, 'external_id' => $track->externalId],
            [
                'recording_id' => $recording->id,
                'status' => ResolutionStatus::Resolved,
                'method' => $method,
                'confidence' => $confidence,
                'query_title' => $query->title,
                'query_artist' => $query->artist,
                'attempts' => 0,
                'resolved_at' => now(),
                'next_attempt_at' => EnrichmentStatus::Done->nextAttemptAt(),
            ],
        );

        $this->telemetry->resolution(ResolutionStatus::Resolved, $method, $confidence);

        return $recording;
    }

    private function recordMiss(TrackToResolve $track, TrackQuery $query): void
    {
        RecordingResolution::query()->updateOrCreate(
            ['provider' => $track->provider, 'external_id' => $track->externalId],
            [
                'recording_id' => null,
                'status' => ResolutionStatus::NotFound,
                'method' => null,
                'confidence' => null,
                'query_title' => $query->title,
                'query_artist' => $query->artist,
                'resolved_at' => null,
                'next_attempt_at' => EnrichmentStatus::NotFound->nextAttemptAt(),
            ],
        );

        $this->telemetry->resolution(ResolutionStatus::NotFound, null, null);
    }

    /**
     * Finds the recording by MusicBrainz id, then by ISRC among recordings
     * without one; creates it otherwise.
     */
    private function recordingFor(?RegistryRecording $registry, ?string $isrc, TrackQuery $query, ?int $duration): Recording
    {
        $values = [
            'isrc' => $isrc,
            'title' => $registry->title ?? $query->title,
            'artist_name' => $registry->artists[0]['name'] ?? $query->artist,
            'duration_seconds' => $registry->durationSeconds ?? $duration,
        ];

        if ($registry !== null) {
            $byIsrcOnly = $isrc === null ? null : Recording::query()->whereNull('mbid')->where('isrc', $isrc)->first();

            if ($byIsrcOnly !== null) {
                $byIsrcOnly->update(['mbid' => $registry->mbid, ...$values]);

                return $byIsrcOnly;
            }

            $recording = Recording::query()->createOrFirst(['mbid' => $registry->mbid], $values);

            if ($recording->isrc === null && $isrc !== null) {
                $recording->update(['isrc' => $isrc]);
            }

            return $recording;
        }

        return Recording::query()->whereNull('mbid')->where('isrc', $isrc)->first()
            ?? Recording::query()->create($values);
    }
}
```

`array_any` exists since PHP 8.4. The ISRC-only `create` cannot race in practice because the `enrichment` queue runs a single process (Task 10); if that ever changes, guard it with a lock on the ISRC.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Unit/Actions/ResolveRecordingsTest.php`
Expected: PASS. The search fixture's first hit has `length` 271000 (271 s) and the second 257413 (257 s): with a source duration of 257 the second is chosen, which is what "falls back to a confident MusicBrainz search" asserts.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Actions/ResolveRecordings.php app/Services/Metadata/Data
git add app tests
git commit -m "feat(enrichment): resolve library tracks to registry recordings"
```

---

### Task 7: Describe recordings and contributors

**Files:**
- Create: `app/Actions/DescribeRecording.php`, `app/Actions/DescribeContributor.php`
- Test: `tests/Unit/Actions/DescribeRecordingTest.php`, `tests/Unit/Actions/DescribeContributorTest.php`

**Interfaces:**
- Consumes: gateways, `Enrichment::store`.
- Produces: `DescribeRecording::handle(Recording $recording, MetadataSource $source): ?EnrichmentStatus` (null when the source cannot describe this recording: credits.fm without ISRC, MusicBrainz without MBID); endpoints stored: credits.fm `isrc`, MusicBrainz `recording`. `DescribeContributor::handle(Contributor $contributor, MetadataSource $source): ?EnrichmentStatus` (MusicBrainz `artist`; null without MBID). `DescribeRecording::SOURCES = [MetadataSource::CreditsFm, MetadataSource::MusicBrainz]`.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Unit/Actions/DescribeRecordingTest.php
<?php

declare(strict_types=1);

use App\Actions\DescribeRecording;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Tests\Support\FakeCreditsFmGateway;
use Tests\Support\FakeMusicBrainzGateway;

beforeEach(function (): void {
    $this->creditsFm = new FakeCreditsFmGateway();
    $this->musicBrainz = new FakeMusicBrainzGateway();
    app()->instance(CreditsFmGateway::class, $this->creditsFm);
    app()->instance(MusicBrainzGateway::class, $this->musicBrainz);
});

it('stores the credits.fm detail of the recording isrc', function (): void {
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    $this->creditsFm->details['GBAHT1200434'] = metadataFixture('credits-fm-isrc');

    $status = resolve(DescribeRecording::class)->handle($recording, MetadataSource::CreditsFm);

    expect($status)->toBe(EnrichmentStatus::Done)
        ->and(Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::CreditsFm, 'isrc')['iswc'] ?? null)->toBe('T-912674410-3');
});

it('stores the MusicBrainz recording, or not found', function (): void {
    $known = Recording::factory()->create(['mbid' => '464d783d-1be7-4e1c-a75b-2b568eb20454']);
    $unknown = Recording::factory()->create();
    $this->musicBrainz->recordings['464d783d-1be7-4e1c-a75b-2b568eb20454'] = metadataFixture('musicbrainz-recording');

    expect(resolve(DescribeRecording::class)->handle($known, MetadataSource::MusicBrainz))->toBe(EnrichmentStatus::Done)
        ->and(resolve(DescribeRecording::class)->handle($unknown, MetadataSource::MusicBrainz))->toBe(EnrichmentStatus::NotFound);
});

it('skips a source that cannot identify the recording', function (): void {
    $recording = Recording::factory()->create(['mbid' => null, 'isrc' => 'GBAHT1200434']);

    expect(resolve(DescribeRecording::class)->handle($recording, MetadataSource::MusicBrainz))->toBeNull()
        ->and($this->musicBrainz->calls)->toBe([]);
});
```

```php
// tests/Unit/Actions/DescribeContributorTest.php
<?php

declare(strict_types=1);

use App\Actions\DescribeContributor;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Tests\Support\FakeMusicBrainzGateway;

it('stores the MusicBrainz artist of a contributor with an mbid', function (): void {
    $musicBrainz = new FakeMusicBrainzGateway();
    $musicBrainz->artists['9c9f1380-2516-4fc9-a3e6-f9f61941d090'] = metadataFixture('musicbrainz-artist');
    app()->instance(MusicBrainzGateway::class, $musicBrainz);
    $muse = Contributor::factory()->create(['mbid' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090']);
    $nameOnly = Contributor::factory()->create(['mbid' => null]);

    expect(resolve(DescribeContributor::class)->handle($muse, MetadataSource::MusicBrainz))->toBe(EnrichmentStatus::Done)
        ->and(Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $muse->id, MetadataSource::MusicBrainz, 'artist'))->not->toBeNull()
        ->and(resolve(DescribeContributor::class)->handle($nameOnly, MetadataSource::MusicBrainz))->toBeNull();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Unit/Actions/DescribeRecordingTest.php tests/Unit/Actions/DescribeContributorTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

```php
// app/Actions/DescribeRecording.php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;

final readonly class DescribeRecording
{
    /** @var list<MetadataSource> */
    public const array SOURCES = [MetadataSource::CreditsFm, MetadataSource::MusicBrainz];

    public function __construct(
        private CreditsFmGateway $creditsFm,
        private MusicBrainzGateway $musicBrainz,
    ) {}

    /**
     * Fetches and stores what one source says about the recording. Null when
     * the source has no identifier to ask with. Failures propagate.
     */
    public function handle(Recording $recording, MetadataSource $source): ?EnrichmentStatus
    {
        [$endpoint, $payload] = match ($source) {
            MetadataSource::CreditsFm => $recording->isrc === null ? [null, null] : ['isrc', $this->creditsFm->isrc($recording->isrc)],
            MetadataSource::MusicBrainz => $recording->mbid === null ? [null, null] : ['recording', $this->musicBrainz->recording($recording->mbid)],
            default => [null, null],
        };

        if ($endpoint === null) {
            return null;
        }

        $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
        Enrichment::store(Enrichment::RECORDING, $recording->id, $source, $endpoint, $status, $payload);

        return $status;
    }
}
```

```php
// app/Actions/DescribeContributor.php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;

final readonly class DescribeContributor
{
    public function __construct(private MusicBrainzGateway $musicBrainz) {}

    /**
     * Fetches and stores what one source says about the contributor. Null
     * when the source has no identifier to ask with.
     */
    public function handle(Contributor $contributor, MetadataSource $source): ?EnrichmentStatus
    {
        if ($source !== MetadataSource::MusicBrainz || $contributor->mbid === null) {
            return null;
        }

        $payload = $this->musicBrainz->artist($contributor->mbid);
        $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
        Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, $source, 'artist', $status, $payload);

        return $status;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Unit/Actions/DescribeRecordingTest.php tests/Unit/Actions/DescribeContributorTest.php`
Expected: PASS.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Actions/DescribeRecording.php app/Actions/DescribeContributor.php
git add app tests
git commit -m "feat(enrichment): store what credits.fm and MusicBrainz say about recordings and artists"
```

---

### Task 8: Project raw payloads into credits and tags

**Files:**
- Create: `app/Actions/ProjectEnrichment.php`, `app/Actions/ProjectContributorTags.php`
- Test: `tests/Unit/Actions/ProjectEnrichmentTest.php`, `tests/Unit/Actions/ProjectContributorTagsTest.php`

**Interfaces:**
- Consumes: `Enrichment::payloadFor`, `CreditsFmMapper`, `MusicBrainzMapper`, `Tag::named`, `MusicText::normalize`.
- Produces: `ProjectEnrichment::handle(Recording $recording): list<Contributor>` (main artists with an MBID and no MusicBrainz `artist` enrichment yet, to describe next); `ProjectContributorTags::handle(Contributor $contributor): void`.

- [ ] **Step 1: Write the failing tests**

```php
// tests/Unit/Actions/ProjectEnrichmentTest.php
<?php

declare(strict_types=1);

use App\Actions\ProjectEnrichment;
use App\Enums\CreditType;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingContributor;
use App\Models\Tag;

function describedSurvival(): Recording
{
    $recording = Recording::factory()->create([
        'mbid' => '464d783d-1be7-4e1c-a75b-2b568eb20454',
        'isrc' => 'GBAHT1200434',
        'release_date' => null,
    ]);
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, metadataFixture('credits-fm-isrc'));
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::MusicBrainz, 'recording', EnrichmentStatus::Done, metadataFixture('musicbrainz-recording'));

    return $recording;
}

it('projects credits from both sources and fills the identity', function (): void {
    $recording = describedSurvival();

    resolve(ProjectEnrichment::class)->handle($recording);

    $recording->refresh();
    expect($recording->iswc)->toBe('T-912674410-3')
        ->and($recording->release_date?->toDateString())->toBe('2012-01-01')
        ->and(RecordingContributor::query()->where('credit_type', CreditType::Songwriter)->count())->toBe(1)
        ->and(RecordingContributor::query()->where('credit_type', CreditType::Artist)->pluck('source')->map->value->sort()->values()->all())->toBe(['credits_fm', 'musicbrainz']);
});

it('shares one contributor between sources through its mbid', function (): void {
    resolve(ProjectEnrichment::class)->handle(describedSurvival());

    expect(Contributor::query()->where('mbid', '9c9f1380-2516-4fc9-a3e6-f9f61941d090')->count())->toBe(1);
});

it('projects MusicBrainz genres as genre tags', function (): void {
    $recording = describedSurvival();

    resolve(ProjectEnrichment::class)->handle($recording);

    $tags = $recording->tags()->get();
    expect($tags->pluck('name')->all())->toContain('art rock')
        ->and(Tag::query()->where('slug', 'art rock')->value('is_genre'))->toBeTrue()
        ->and($tags->first()?->pivot?->weight)->toBe(100);
});

it('projects twice without duplicating', function (): void {
    $recording = describedSurvival();

    resolve(ProjectEnrichment::class)->handle($recording);
    $counts = [Contributor::query()->count(), RecordingContributor::query()->count(), $recording->tags()->count()];
    resolve(ProjectEnrichment::class)->handle($recording);

    expect([Contributor::query()->count(), RecordingContributor::query()->count(), $recording->tags()->count()])->toBe($counts);
});

it('reuses a name-only contributor instead of creating it again', function (): void {
    $recording = describedSurvival();
    resolve(ProjectEnrichment::class)->handle($recording);
    $writers = Contributor::query()->where('normalized_name', 'matthew james bellamy')->count();

    $other = Recording::factory()->create(['isrc' => 'GBAHT1200999', 'mbid' => null]);
    Enrichment::store(Enrichment::RECORDING, $other->id, MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, metadataFixture('credits-fm-isrc'));
    resolve(ProjectEnrichment::class)->handle($other);

    expect(Contributor::query()->where('normalized_name', 'matthew james bellamy')->count())->toBe($writers);
});

it('names the main artists still to describe', function (): void {
    $toDescribe = resolve(ProjectEnrichment::class)->handle(describedSurvival());

    expect(array_map(fn (Contributor $contributor): ?string => $contributor->mbid, $toDescribe))->toBe(['9c9f1380-2516-4fc9-a3e6-f9f61941d090']);
});
```

```php
// tests/Unit/Actions/ProjectContributorTagsTest.php
<?php

declare(strict_types=1);

use App\Actions\ProjectContributorTags;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;

it('projects the artist tags and rebuilds them on refresh', function (): void {
    $muse = Contributor::factory()->create(['mbid' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090']);
    Enrichment::store(Enrichment::CONTRIBUTOR, $muse->id, MetadataSource::MusicBrainz, 'artist', EnrichmentStatus::Done, metadataFixture('musicbrainz-artist'));

    resolve(ProjectContributorTags::class)->handle($muse);
    resolve(ProjectContributorTags::class)->handle($muse);

    $alternativeRock = $muse->tags()->where('slug', 'alternative rock')->first();
    expect($alternativeRock?->pivot?->weight)->toBe(100)
        ->and($muse->tags()->count())->toBe($muse->tags()->distinct()->count('tags.id'));
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Unit/Actions/ProjectEnrichmentTest.php tests/Unit/Actions/ProjectContributorTagsTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

```php
// app/Actions/ProjectEnrichment.php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CreditType;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingContributor;
use App\Models\Tag;
use App\Services\Metadata\CreditsFm\CreditsFmMapper;
use App\Services\Metadata\Data\Credit;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;
use App\Support\MusicText;
use Illuminate\Support\Facades\DB;

final readonly class ProjectEnrichment
{
    /**
     * Rebuilds the recording's credits and tags from every stored payload,
     * and fills identity fields it lacks. Safe to run again at any time.
     *
     * @return list<Contributor> main artists with an MBID MusicBrainz has not described yet
     */
    public function handle(Recording $recording): array
    {
        $creditsFm = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::CreditsFm, 'isrc');
        $musicBrainz = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::MusicBrainz, 'recording');

        /** @var list<array{credit: Credit, source: MetadataSource}> $credits */
        $credits = [];

        foreach ($creditsFm === null ? [] : CreditsFmMapper::credits($creditsFm) as $credit) {
            $credits[] = ['credit' => $credit, 'source' => MetadataSource::CreditsFm];
        }

        $registry = $musicBrainz === null ? null : MusicBrainzMapper::recording($musicBrainz);

        foreach ($registry === null ? [] : $registry->artists as $artist) {
            $credits[] = ['credit' => new Credit($artist['name'], CreditType::Artist, '', $artist['mbid'] ?: null, null), 'source' => MetadataSource::MusicBrainz];
        }

        $tags = $musicBrainz === null ? [] : MusicBrainzMapper::tags($musicBrainz);
        $detail = $creditsFm === null ? null : CreditsFmMapper::detail($creditsFm);

        return DB::transaction(function () use ($recording, $credits, $tags, $detail, $registry): array {
            $recording->update(array_filter([
                'iswc' => $recording->iswc ?? $detail?->iswc,
                'release_date' => $recording->release_date ?? $detail?->releaseDate ?? $registry?->firstReleaseDate,
            ], fn (mixed $value): bool => $value !== null));

            RecordingContributor::query()->where('recording_id', $recording->id)->delete();
            DB::table('recording_tags')->where('recording_id', $recording->id)->delete();

            $mainArtists = [];

            foreach ($credits as ['credit' => $credit, 'source' => $source]) {
                $contributor = $this->contributorFor($credit);

                RecordingContributor::query()->createOrFirst([
                    'recording_id' => $recording->id,
                    'contributor_id' => $contributor->id,
                    'credit_type' => $credit->type,
                    'role' => $credit->role,
                    'source' => $source,
                ], ['attributes' => $credit->attributes]);

                if ($credit->type === CreditType::Artist && $contributor->mbid !== null) {
                    $mainArtists[$contributor->id] = $contributor;
                }
            }

            foreach ($tags as $tag) {
                DB::table('recording_tags')->insertOrIgnore([
                    'recording_id' => $recording->id,
                    'tag_id' => Tag::named($tag->name, $tag->isGenre)->id,
                    'source' => MetadataSource::MusicBrainz->value,
                    'weight' => $tag->weight,
                ]);
            }

            return array_values(array_filter(
                $mainArtists,
                fn (Contributor $contributor): bool => Enrichment::query()
                    ->where('subject_type', Enrichment::CONTRIBUTOR)
                    ->where('subject_key', $contributor->id)
                    ->where('source', MetadataSource::MusicBrainz)
                    ->doesntExist(),
            ));
        });
    }

    /**
     * By MBID, then IPI, then an existing name-only contributor with the
     * same normalised name; created otherwise.
     */
    private function contributorFor(Credit $credit): Contributor
    {
        $normalized = MusicText::normalize($credit->name);
        $values = ['name' => $credit->name, 'normalized_name' => $normalized];

        if ($credit->mbid !== null) {
            return Contributor::query()->createOrFirst(['mbid' => $credit->mbid], [...$values, 'ipi' => $credit->ipi]);
        }

        if ($credit->ipi !== null) {
            return Contributor::query()->createOrFirst(['ipi' => $credit->ipi], $values);
        }

        return Contributor::query()
            ->whereNull('mbid')
            ->whereNull('ipi')
            ->where('normalized_name', $normalized)
            ->first()
            ?? Contributor::query()->create($values);
    }
}
```

```php
// app/Actions/ProjectContributorTags.php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Tag;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;
use Illuminate\Support\Facades\DB;

final readonly class ProjectContributorTags
{
    /**
     * Rebuilds the contributor's tags from its stored MusicBrainz artist.
     */
    public function handle(Contributor $contributor): void
    {
        $payload = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::MusicBrainz, 'artist');

        DB::transaction(function () use ($contributor, $payload): void {
            DB::table('contributor_tags')
                ->where('contributor_id', $contributor->id)
                ->where('source', MetadataSource::MusicBrainz->value)
                ->delete();

            foreach ($payload === null ? [] : MusicBrainzMapper::tags($payload) as $tag) {
                DB::table('contributor_tags')->insertOrIgnore([
                    'contributor_id' => $contributor->id,
                    'tag_id' => Tag::named($tag->name, $tag->isGenre)->id,
                    'source' => MetadataSource::MusicBrainz->value,
                    'weight' => $tag->weight,
                ]);
            }
        });
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Unit/Actions/ProjectEnrichmentTest.php tests/Unit/Actions/ProjectContributorTagsTest.php`
Expected: PASS.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Actions/ProjectEnrichment.php app/Actions/ProjectContributorTags.php
git add app tests
git commit -m "feat(enrichment): project raw metadata into credits and weighted tags"
```

---

### Task 9: Jobs chaining the pipeline

**Files:**
- Create: `app/Jobs/{ResolveLibraryTracks,EnrichRecording,EnrichContributor,ProjectRecordingMetadata}.php`
- Test: `tests/Unit/Jobs/EnrichmentJobsTest.php`

**Interfaces:**
- Consumes: Actions from Tasks 6–8.
- Produces:
  - `ResolveLibraryTracks(list<array{provider: string, externalId: string, title: string, artists: string, durationSeconds: int|null}> $tracks)`, dispatches `EnrichRecording` per resolved recording and source.
  - `EnrichRecording(string $recordingId, MetadataSource $source)`, dispatches `ProjectRecordingMetadata`.
  - `ProjectRecordingMetadata(string $recordingId)`, dispatches `EnrichContributor` per returned contributor.
  - `EnrichContributor(string $contributorId, MetadataSource $source)`, then projects contributor tags.
  - All on queue `enrichment`.

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Jobs/EnrichmentJobsTest.php
<?php

declare(strict_types=1);

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Jobs\EnrichContributor;
use App\Jobs\EnrichRecording;
use App\Jobs\ProjectRecordingMetadata;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeCreditsFmGateway;
use Tests\Support\FakeMusicBrainzGateway;

beforeEach(function (): void {
    $this->creditsFm = new FakeCreditsFmGateway();
    $this->musicBrainz = new FakeMusicBrainzGateway();
    app()->instance(CreditsFmGateway::class, $this->creditsFm);
    app()->instance(MusicBrainzGateway::class, $this->musicBrainz);
});

it('resolves tracks then asks each source about the recording', function (): void {
    Queue::fake();
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    (new ResolveLibraryTracks([['provider' => 'youtube_music', 'externalId' => 'UcOUJM08bYk', 'title' => 'Survival', 'artists' => 'Muse', 'durationSeconds' => 258]]))->handle();

    Queue::assertPushedOn('enrichment', EnrichRecording::class, fn (EnrichRecording $job): bool => $job->source === MetadataSource::CreditsFm);
    Queue::assertPushedOn('enrichment', EnrichRecording::class, fn (EnrichRecording $job): bool => $job->source === MetadataSource::MusicBrainz);
});

it('describes then projects a recording', function (): void {
    Queue::fake();
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    $this->creditsFm->details['GBAHT1200434'] = metadataFixture('credits-fm-isrc');

    (new EnrichRecording($recording->id, MetadataSource::CreditsFm))->handle();

    Queue::assertPushedOn('enrichment', ProjectRecordingMetadata::class, fn (ProjectRecordingMetadata $job): bool => $job->recordingId === $recording->id);
});

it('describes the main artists the projection names', function (): void {
    Queue::fake();
    $recording = Recording::factory()->create(['mbid' => '464d783d-1be7-4e1c-a75b-2b568eb20454']);
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::MusicBrainz, 'recording', EnrichmentStatus::Done, metadataFixture('musicbrainz-recording'));

    (new ProjectRecordingMetadata($recording->id))->handle();

    Queue::assertPushedOn('enrichment', EnrichContributor::class);
});

it('projects the contributor tags after describing it', function (): void {
    $muse = Contributor::factory()->create(['mbid' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090']);
    $this->musicBrainz->artists['9c9f1380-2516-4fc9-a3e6-f9f61941d090'] = metadataFixture('musicbrainz-artist');

    (new EnrichContributor($muse->id, MetadataSource::MusicBrainz))->handle();

    expect($muse->tags()->count())->toBeGreaterThan(0);
});

it('releases on a rate limit without recording a failure', function (): void {
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    $this->creditsFm->failure = new MetadataSourceRateLimited(MetadataSource::CreditsFm, 12);
    $job = (new EnrichRecording($recording->id, MetadataSource::CreditsFm))->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased(12);
    expect(Enrichment::query()->count())->toBe(0);
});

it('records a failure once the job gives up', function (): void {
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);

    (new EnrichRecording($recording->id, MetadataSource::CreditsFm))->failed(new MetadataSourceUnavailable(MetadataSource::CreditsFm, 'down'));

    $enrichment = Enrichment::query()->sole();
    expect($enrichment->status)->toBe(EnrichmentStatus::Failed)
        ->and($enrichment->endpoint)->toBe('isrc')
        ->and($enrichment->next_attempt_at?->toIso8601String())->toBe(now()->addDay()->toIso8601String());
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest tests/Unit/Jobs/EnrichmentJobsTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

Shared settings are repeated in each job (no base class; the codebase has none):

```php
// app/Jobs/EnrichRecording.php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\DescribeRecording;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Models\Enrichment;
use App\Models\Recording;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks one metadata source about one recording, then projects the result.
 */
final class EnrichRecording implements ShouldQueue
{
    use Queueable;

    /**
     * Releases for a rate limit are not exceptions: they may repeat until
     * `retryUntil()`. Real failures get three tries.
     */
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $recordingId,
        public readonly MetadataSource $source,
    ) {
        $this->onQueue('enrichment');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(hours: 2);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(): void
    {
        $recording = Recording::query()->find($this->recordingId);

        if ($recording === null) {
            return;
        }

        try {
            $status = resolve(DescribeRecording::class)->handle($recording, $this->source);
        } catch (MetadataSourceRateLimited $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        if ($status !== null) {
            ProjectRecordingMetadata::dispatch($recording->id);
        }
    }

    public function failed(Throwable $exception): void
    {
        $endpoint = match ($this->source) {
            MetadataSource::CreditsFm => 'isrc',
            default => 'recording',
        };

        Enrichment::store(Enrichment::RECORDING, $this->recordingId, $this->source, $endpoint, EnrichmentStatus::Failed, error: $exception->getMessage());
    }
}
```

```php
// app/Jobs/ProjectRecordingMetadata.php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ProjectEnrichment;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Recording;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Rebuilds one recording's structured metadata from its stored payloads.
 */
final class ProjectRecordingMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $recordingId)
    {
        $this->onQueue('enrichment');
    }

    /**
     * Two sources may finish together; their projections take turns.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->recordingId))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(): void
    {
        $recording = Recording::query()->find($this->recordingId);

        if ($recording === null) {
            return;
        }

        foreach (resolve(ProjectEnrichment::class)->handle($recording) as $contributor) {
            /** @var Contributor $contributor */
            EnrichContributor::dispatch($contributor->id, MetadataSource::MusicBrainz);
        }
    }
}
```

```php
// app/Jobs/EnrichContributor.php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\DescribeContributor;
use App\Actions\ProjectContributorTags;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Models\Contributor;
use App\Models\Enrichment;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asks one metadata source about one main artist, then projects its tags.
 */
final class EnrichContributor implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $contributorId,
        public readonly MetadataSource $source,
    ) {
        $this->onQueue('enrichment');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(hours: 2);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(): void
    {
        $contributor = Contributor::query()->find($this->contributorId);

        if ($contributor === null) {
            return;
        }

        try {
            $status = resolve(DescribeContributor::class)->handle($contributor, $this->source);
        } catch (MetadataSourceRateLimited $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        if ($status !== null) {
            resolve(ProjectContributorTags::class)->handle($contributor);
        }
    }

    public function failed(Throwable $exception): void
    {
        Enrichment::store(Enrichment::CONTRIBUTOR, $this->contributorId, $this->source, 'artist', EnrichmentStatus::Failed, error: $exception->getMessage());
    }
}
```

```php
// app/Jobs/ResolveLibraryTracks.php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\DescribeRecording;
use App\Actions\ResolveRecordings;
use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Models\RecordingResolution;
use App\Services\Metadata\Data\TrackToResolve;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Resolves up to ResolveRecordings::MAX_TRACKS library tracks, then starts
 * describing what they resolved to.
 */
final class ResolveLibraryTracks implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    /**
     * @param  list<array{provider: string, externalId: string, title: string, artists: string, durationSeconds: int|null}>  $tracks
     */
    public function __construct(public readonly array $tracks)
    {
        $this->onQueue('enrichment');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(hours: 2);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(): void
    {
        try {
            $recordings = resolve(ResolveRecordings::class)->handle($this->toResolve());
        } catch (MetadataSourceRateLimited $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        foreach ($recordings as $recording) {
            foreach (DescribeRecording::SOURCES as $source) {
                EnrichRecording::dispatch($recording->id, $source);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        foreach ($this->toResolve() as $track) {
            RecordingResolution::query()->updateOrCreate(
                ['provider' => $track->provider, 'external_id' => $track->externalId],
                [
                    'status' => ResolutionStatus::Failed,
                    'query_title' => $track->title,
                    'query_artist' => $track->artists,
                    'next_attempt_at' => now()->addDay(),
                ],
            );
        }
    }

    /**
     * @return list<TrackToResolve>
     */
    private function toResolve(): array
    {
        return array_map(fn (array $track): TrackToResolve => new TrackToResolve(
            Provider::from($track['provider']),
            $track['externalId'],
            $track['title'],
            $track['artists'],
            $track['durationSeconds'],
        ), $this->tracks);
    }
}
```

`failed()` must not overwrite tracks the batch already resolved before the error: add `->where('status', '!=', ResolutionStatus::Resolved)` semantics by checking first:

```php
            $existing = RecordingResolution::query()
                ->where('provider', $track->provider)
                ->where('external_id', $track->externalId)
                ->first();

            if ($existing?->status === ResolutionStatus::Resolved) {
                continue;
            }
```

Insert this at the top of the `foreach` in `failed()`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Unit/Jobs/EnrichmentJobsTest.php`
Expected: PASS.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Jobs
git add app tests
git commit -m "feat(enrichment): chain resolution, description and projection on the enrichment queue"
```

---

### Task 10: Commands, schedule, post-sync hook and coverage

**Files:**
- Create: `app/Actions/QueueTrackResolution.php`, `app/Actions/RecordEnrichmentCoverage.php`
- Create: `app/Console/Commands/{EnrichMetadataCommand,RetryDueMetadataCommand,ReprojectMetadataCommand}.php`
- Modify: `routes/console.php`, `app/Jobs/SyncYouTubeMusicLibrary.php`, `composer.json` (dev scripts)
- Test: `tests/Unit/Actions/QueueTrackResolutionTest.php`, `tests/Unit/Actions/RecordEnrichmentCoverageTest.php`, `tests/Feature/Commands/MetadataCommandsTest.php`, and one case added to `tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php`

**Interfaces:**
- Produces: `QueueTrackResolution::handle(?YouTubeMusicAccount $account = null, bool $refresh = false): int` (number of tracks queued; one `ResolveLibraryTracks` per 25 distinct video ids; without `$refresh`, only video ids with no resolution); `RecordEnrichmentCoverage::handle(): void` (records coverage facets `resolved`, `credits`, `genres` and backlog `failed`, `not_found`, `unresolved`).

- [ ] **Step 1: Write the failing tests**

There is no `TrackFactory`: the codebase creates tracks through `$playlist->tracks()->create([...])`. Add this helper to `tests/Pest.php`:

```php
function libraryTrack(?App\Models\Playlist $playlist, ?string $videoId, string $title = 'Survival', string $artists = 'Muse'): App\Models\Track
{
    return ($playlist ?? App\Models\Playlist::factory()->create())->tracks()->create([
        'youtube_video_id' => $videoId,
        'title' => $title,
        'artists' => $artists,
        'duration_seconds' => 258,
    ]);
}
```

```php
// tests/Unit/Actions/QueueTrackResolutionTest.php
<?php

declare(strict_types=1);

use App\Actions\QueueTrackResolution;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Playlist;
use App\Models\RecordingResolution;
use App\Models\Track;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\Queue;

it('queues each unresolved video once, in batches of 25', function (): void {
    Queue::fake();
    $account = YouTubeMusicAccount::factory()->create();
    $playlist = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    $other = Playlist::factory()->for($account, 'youtubeMusicAccount')->create();
    foreach (range(0, 29) as $index) {
        libraryTrack($playlist, sprintf('video%06d', $index));
    }
    libraryTrack($other, 'video000000');
    libraryTrack($other, null);
    RecordingResolution::factory()->create(['external_id' => 'video000001']);

    $queued = resolve(QueueTrackResolution::class)->handle($account);

    expect($queued)->toBe(29);
    Queue::assertPushed(ResolveLibraryTracks::class, 2);
    Queue::assertPushed(ResolveLibraryTracks::class, fn (ResolveLibraryTracks $job): bool => count($job->tracks) === 25);
});

it('queues resolved videos again when refreshing', function (): void {
    Queue::fake();
    libraryTrack(null, 'video000001');
    RecordingResolution::factory()->create(['external_id' => 'video000001']);

    expect(resolve(QueueTrackResolution::class)->handle(refresh: true))->toBe(1);
});
```

```php
// tests/Unit/Actions/RecordEnrichmentCoverageTest.php
<?php

declare(strict_types=1);

use App\Actions\RecordEnrichmentCoverage;
use App\Enums\ResolutionStatus;
use App\Models\RecordingResolution;
use App\Models\Track;
use App\Services\Metadata\EnrichmentTelemetry;
use Tests\Support\FakeEnrichmentTelemetry;

it('records the share of the library resolved and the backlog', function (): void {
    $telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $telemetry);
    foreach (['video000001', 'video000002', 'video000003', 'video000004'] as $video) {
        libraryTrack(null, $video);
    }
    RecordingResolution::factory()->create(['external_id' => 'video000001']);
    RecordingResolution::factory()->create(['external_id' => 'video000002', 'status' => ResolutionStatus::NotFound, 'recording_id' => null]);

    resolve(RecordEnrichmentCoverage::class)->handle();

    expect($telemetry->coverage['resolved'])->toBe(0.25)
        ->and($telemetry->backlog['not_found'])->toBe(1)
        ->and($telemetry->backlog['unresolved'])->toBe(2);
});
```

```php
// tests/Feature/Commands/MetadataCommandsTest.php
<?php

declare(strict_types=1);

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Enums\ResolutionStatus;
use App\Jobs\EnrichRecording;
use App\Jobs\ProjectRecordingMetadata;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\Track;
use Illuminate\Support\Facades\Queue;

it('queues the whole library for resolution', function (): void {
    Queue::fake();
    libraryTrack(null, 'video000001');

    $this->artisan('metadata:enrich')->assertSuccessful();

    Queue::assertPushed(ResolveLibraryTracks::class);
});

it('retries what is due and only that', function (): void {
    Queue::fake();
    $due = Recording::factory()->create();
    $fresh = Recording::factory()->create();
    Enrichment::factory()->create(['subject_key' => $due->id, 'source' => MetadataSource::CreditsFm, 'endpoint' => 'isrc', 'status' => EnrichmentStatus::Failed, 'next_attempt_at' => now()->subMinute()]);
    Enrichment::factory()->create(['subject_key' => $fresh->id, 'next_attempt_at' => now()->addDay()]);
    libraryTrack(null, 'video000009');
    RecordingResolution::factory()->create(['external_id' => 'video000009', 'status' => ResolutionStatus::NotFound, 'recording_id' => null, 'next_attempt_at' => now()->subMinute()]);

    $this->artisan('metadata:retry-due')->assertSuccessful();

    Queue::assertPushed(EnrichRecording::class, 1);
    Queue::assertPushed(EnrichRecording::class, fn (EnrichRecording $job): bool => $job->recordingId === $due->id && $job->source === MetadataSource::CreditsFm);
    Queue::assertPushed(ResolveLibraryTracks::class, fn (ResolveLibraryTracks $job): bool => $job->tracks[0]['externalId'] === 'video000009');
});

it('reprojects every recording without calling any service', function (): void {
    Queue::fake();
    Recording::factory()->count(2)->create();

    $this->artisan('metadata:reproject')->assertSuccessful();

    Queue::assertPushed(ProjectRecordingMetadata::class, 2);
});
```

Add to `tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php`:

```php
it('queues the new tracks for enrichment once the sync completes', function (): void {
    Event::fake([YouTubeMusicSyncUpdated::class]);
    Queue::fake([ResolveLibraryTracks::class]);
    $account = YouTubeMusicAccount::factory()->create();
    $sync = YouTubeMusicSync::factory()->for($account, 'youtubeMusicAccount')->create();
    $this->fakeProvider()->playlists = [FakeProviderAdapter::aPlaylistSummary(id: 'PL1', title: 'Deep Focus')];
    $this->fakeProvider()->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1', title: 'Deep Focus');

    (new SyncYouTubeMusicLibrary($sync->id, $account->id))->handle(resolve(SyncPlaylistsFromYouTubeMusicAction::class));

    Queue::assertPushed(ResolveLibraryTracks::class);
});
```

(Add `use App\Jobs\ResolveLibraryTracks;` and `use Illuminate\Support\Facades\Queue;` to that file. If `FakeProviderAdapter::aPlaylist()` builds a playlist without tracks, give it one track with a video id through its parameters; read `tests/Support/FakeProviderAdapter.php` for the signature.)

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Unit/Actions/QueueTrackResolutionTest.php tests/Unit/Actions/RecordEnrichmentCoverageTest.php tests/Feature/Commands/MetadataCommandsTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
// app/Actions/QueueTrackResolution.php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Provider;
use App\Jobs\ResolveLibraryTracks;
use App\Models\RecordingResolution;
use App\Models\Track;
use App\Models\YouTubeMusicAccount;

final readonly class QueueTrackResolution
{
    /**
     * Queues the library's YouTube Music videos for resolution, one job per
     * ResolveRecordings::MAX_TRACKS distinct videos. Without $refresh, only
     * videos never resolved.
     */
    public function handle(?YouTubeMusicAccount $account = null, bool $refresh = false): int
    {
        $tracks = Track::query()
            ->whereNotNull('youtube_video_id')
            ->when($account, fn ($query) => $query->whereHas('playlist', fn ($playlists) => $playlists->where('youtube_music_account_id', $account?->id)))
            ->when(! $refresh, fn ($query) => $query->whereNotIn(
                'youtube_video_id',
                RecordingResolution::query()->where('provider', Provider::YouTubeMusic)->select('external_id'),
            ))
            ->orderBy('youtube_video_id')
            ->get(['youtube_video_id', 'title', 'artists', 'duration_seconds'])
            ->unique('youtube_video_id')
            ->values();

        foreach ($tracks->chunk(ResolveRecordings::MAX_TRACKS) as $chunk) {
            ResolveLibraryTracks::dispatch($chunk->map(fn (Track $track): array => [
                'provider' => Provider::YouTubeMusic->value,
                'externalId' => (string) $track->youtube_video_id,
                'title' => $track->title,
                'artists' => $track->artists,
                'durationSeconds' => $track->duration_seconds,
            ])->values()->all());
        }

        return $tracks->count();
    }
}
```

Check the column linking playlists to accounts in `app/Models/Playlist.php` (`youtube_music_account_id` is assumed; use the real foreign key name).

```php
// app/Actions/RecordEnrichmentCoverage.php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\Track;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Support\Facades\DB;

final readonly class RecordEnrichmentCoverage
{
    public function __construct(private EnrichmentTelemetry $telemetry) {}

    /**
     * Measures how much of the library carries each kind of metadata, and
     * what waits to be fetched again.
     */
    public function handle(): void
    {
        $videos = Track::query()->whereNotNull('youtube_video_id')->distinct()->count('youtube_video_id');
        $resolutions = RecordingResolution::query()->where('provider', Provider::YouTubeMusic);
        $resolved = (clone $resolutions)->where('status', ResolutionStatus::Resolved)->count();
        $recordings = max(1, Recording::query()->count());

        $this->telemetry->coverage('resolved', $videos === 0 ? 0.0 : $resolved / $videos);
        $this->telemetry->coverage('credits', DB::table('recording_contributors')->where('credit_type', '!=', 'artist')->distinct()->count('recording_id') / $recordings);
        $this->telemetry->coverage('genres', DB::table('recording_tags')->join('tags', 'tags.id', '=', 'recording_tags.tag_id')->where('tags.is_genre', true)->distinct()->count('recording_id') / $recordings);

        $this->telemetry->backlog('failed', Enrichment::query()->where('status', EnrichmentStatus::Failed)->count() + (clone $resolutions)->where('status', ResolutionStatus::Failed)->count());
        $this->telemetry->backlog('not_found', (clone $resolutions)->where('status', ResolutionStatus::NotFound)->count());
        $this->telemetry->backlog('unresolved', max(0, $videos - (clone $resolutions)->count()));
    }
}
```

```php
// app/Console/Commands/EnrichMetadataCommand.php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\QueueTrackResolution;
use App\Actions\RecordEnrichmentCoverage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metadata:enrich {--refresh : Resolve again the tracks already resolved}')]
#[Description('Queue the library for metadata enrichment (credits.fm, MusicBrainz)')]
final class EnrichMetadataCommand extends Command
{
    public function handle(QueueTrackResolution $queue, RecordEnrichmentCoverage $coverage): int
    {
        $queued = $queue->handle(refresh: (bool) $this->option('refresh'));
        $coverage->handle();

        $this->components->info("Queued {$queued} tracks for enrichment.");

        return self::SUCCESS;
    }
}
```

```php
// app/Console/Commands/RetryDueMetadataCommand.php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecordEnrichmentCoverage;
use App\Enums\MetadataSource;
use App\Jobs\EnrichContributor;
use App\Jobs\EnrichRecording;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Enrichment;
use App\Models\RecordingResolution;
use App\Models\Track;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metadata:retry-due')]
#[Description('Fetch again the metadata that failed, was missing or went stale')]
final class RetryDueMetadataCommand extends Command
{
    public function handle(RecordEnrichmentCoverage $coverage): int
    {
        $retried = 0;

        Enrichment::query()
            ->whereIn('subject_type', [Enrichment::RECORDING, Enrichment::CONTRIBUTOR])
            ->where('next_attempt_at', '<=', now())
            ->lazyById()
            ->each(function (Enrichment $enrichment) use (&$retried): void {
                $retried++;

                if ($enrichment->subject_type === Enrichment::RECORDING) {
                    EnrichRecording::dispatch($enrichment->subject_key, $enrichment->source);
                } else {
                    EnrichContributor::dispatch($enrichment->subject_key, $enrichment->source);
                }
            });

        RecordingResolution::query()
            ->where('next_attempt_at', '<=', now())
            ->get(['external_id'])
            ->chunk(25)
            ->each(function ($chunk) use (&$retried): void {
                $tracks = Track::query()
                    ->whereIn('youtube_video_id', $chunk->pluck('external_id'))
                    ->get(['youtube_video_id', 'title', 'artists', 'duration_seconds'])
                    ->unique('youtube_video_id');

                $retried += $tracks->count();

                if ($tracks->isNotEmpty()) {
                    ResolveLibraryTracks::dispatch($tracks->map(fn (Track $track): array => [
                        'provider' => 'youtube_music',
                        'externalId' => (string) $track->youtube_video_id,
                        'title' => $track->title,
                        'artists' => $track->artists,
                        'durationSeconds' => $track->duration_seconds,
                    ])->values()->all());
                }
            });

        $coverage->handle();
        $this->components->info("Queued {$retried} metadata lookups again.");

        return self::SUCCESS;
    }
}
```

Unused import `MetadataSource` in that file: remove it if Pint or PHPStan flags it.

```php
// app/Console/Commands/ReprojectMetadataCommand.php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProjectRecordingMetadata;
use App\Models\Recording;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metadata:reproject')]
#[Description('Rebuild credits and tags from the stored raw metadata, without calling any service')]
final class ReprojectMetadataCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        Recording::query()->lazyById()->each(function (Recording $recording) use (&$count): void {
            ProjectRecordingMetadata::dispatch($recording->id);
            $count++;
        });

        $this->components->info("Queued {$count} recordings for projection.");

        return self::SUCCESS;
    }
}
```

In `routes/console.php`, below the existing schedule:

```php
Schedule::command('metadata:retry-due')->daily();
```

In `app/Jobs/SyncYouTubeMusicLibrary.php`, right after the sync is marked `Completed` (same `try` block), add:

```php
            resolve(QueueTrackResolution::class)->handle($sync->youtubeMusicAccount);
```

In `composer.json`, in both `dev` scripts, change `php artisan queue:listen --tries=1` to `php artisan queue:listen --tries=1 --queue=default,enrichment`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Unit/Actions/QueueTrackResolutionTest.php tests/Unit/Actions/RecordEnrichmentCoverageTest.php tests/Feature/Commands/MetadataCommandsTest.php tests/Unit/Jobs/SyncYouTubeMusicLibraryTest.php`
Expected: PASS.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app/Actions app/Console app/Jobs
git add app routes composer.json tests
git commit -m "feat(enrichment): queue the library after each sync and retry due metadata daily"
```

---

### Task 11: Architecture rules and full verification

**Files:**
- Modify: `tests/Unit/ArchTest.php`

- [ ] **Step 1: Add the rules**

```php
arch('actions reach metadata services through their gateway contracts')
    ->expect('App\Actions')
    ->not->toUse([
        'App\Services\Metadata\CreditsFm\HttpCreditsFmGateway',
        'App\Services\Metadata\CreditsFm\RateLimitedCreditsFmGateway',
        'App\Services\Metadata\MusicBrainz\HttpMusicBrainzGateway',
        'App\Services\Metadata\MusicBrainz\RateLimitedMusicBrainzGateway',
    ]);

arch('only the metadata gateways call metadata services over HTTP')
    ->expect('App\Services\Metadata')
    ->not->toUse('Illuminate\Support\Facades\Http')
    ->ignoring([
        'App\Services\Metadata\CreditsFm\HttpCreditsFmGateway',
        'App\Services\Metadata\MusicBrainz\HttpMusicBrainzGateway',
    ]);
```

- [ ] **Step 2: Run the full verification**

```bash
vendor/bin/pest tests/Unit/ArchTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze --level 8 app tests/Support
./vendor/bin/pest --parallel
```

Expected: all PASS.

- [ ] **Step 3: Try it for real (with the user)**

Ask the user for `MUSICBRAINZ_CONTACT` and put it in their `.env`. Restart `composer run dev` so the worker listens on `default,enrichment`. Then:

```bash
php artisan metadata:enrich
```

After a few minutes, check with the `database-query` Boost tool:

```sql
SELECT status, method, count(*) FROM recording_resolutions GROUP BY 1, 2;
SELECT t.name, count(*) FROM recording_tags rt JOIN tags t ON t.id = rt.tag_id GROUP BY 1 ORDER BY 2 DESC LIMIT 15;
```

and in Grafana (Grafana MCP, `query_prometheus` on `grafanacloud-prom`): `sum by (source, outcome) (increase(sonder_enrichment_lookups_total[15m]))`.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/ArchTest.php
git commit -m "test(enrichment): keep actions on gateway contracts and HTTP in the gateways"
```

- [ ] **Step 5: Production note for the user**

Production runs on Laravel Cloud: its queue worker must also process `enrichment` (queues `default,enrichment`, one process), and `MUSICBRAINZ_CONTACT` must be set in the environment. Tell the user; use the `deploying-to-cloud` skill when they ask to deploy.
