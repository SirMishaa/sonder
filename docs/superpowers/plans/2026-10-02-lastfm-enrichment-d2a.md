# Last.fm Enrichment (D2a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Describe every recording and main artist with Last.fm (weighted tags, similar tracks and artists, listeners and play count), resolve through Last.fm the tracks the registries miss, keep a popularity history and snapshot four charts daily.

**Architecture:** A third metadata source shaped like credits.fm and MusicBrainz: an HTTP gateway behind `LastFmGateway`, a rate-limited decorator spending a `lastfm` limiter through `CallBudget`, and a pure `LastFmMapper`. `ResolveRecordings` gains a last step creating name-only recordings (identified by `match_title` / `match_artist`). `DescribeRecording` / `DescribeContributor` ask Last.fm's three endpoints when due; the projections fill tags, similar rows, popularity and samples. A daily command snapshots charts.

**Tech Stack:** Laravel 13 (PHP 8.5), PostgreSQL, Pest, Laravel HTTP client, keepsuit/laravel-opentelemetry, database queue.

**Spec:** `docs/superpowers/specs/2026-10-02-lastfm-enrichment-design.md` (read it first), within `docs/superpowers/specs/2026-10-01-metadata-enrichment-design.md`.

## Global Constraints

- Every PHP file: `declare(strict_types=1);`, final classes, constructor promotion, explicit types, curly braces, concise class/method docblocks, strongest PHPStan generics. No inline comments except for genuinely non-obvious logic.
- Actions live in `app/Actions`, no suffix, one public `handle()` (helpers may be public when another class needs them, as `DescribeRecording::sources()`).
- New files via `php artisan make:* --no-interaction` where a generator exists (`make:migration`, `make:model`, `make:job`, `make:command`, `make:action`, `make:class`, `make:test --pest`). Rename `…Action` files the generator suffixes.
- After PHP changes: `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyze <files>` (project config: `level: max`, includes tests). Never `--level 8`. Never add `@phpstan-ignore`, baseline entries or inline `@var` to silence an error; narrow `mixed` with `is_array()` / `is_string()` / `is_numeric()`.
- `composer test:type-coverage` must stay at 100 %: every closure has typed parameters and a return type.
- Read config with `config()->string('…')` / `config()->integer('…')`, never `(string) config(...)`.
- Tests: Pest. Narrow runs `vendor/bin/pest --tia --parallel <one path>` (one path per run). `tests/Pest.php` already calls `Http::preventStrayRequests()`, `Sleep::fake()`, `freezeTime()` and binds fake credits.fm and MusicBrainz gateways for every test. Never hit the network in tests.
- Commits: Conventional Commits, `type(scope): subject`, lower case, no trailing period, **no `Co-Authored-By` or any AI attribution**. Before each commit: `timeout 10 sh -c 'echo test | gpg --batch --pinentry-mode error --local-user $(git config user.signingkey) -s -o /dev/null'; echo "exit=$?"`. `exit=0`: `git commit`. Otherwise: `timeout 60 git -c commit.gpgsign=false commit …` and say so. Never let pinentry prompt.
- Never print, log or store the Last.fm API key. It travels only in the request query string.
- Last.fm (spec): every call sends `api_key`, `format=json`, `autocorrect=1`. Limiter `lastfm`: 4 calls a second. Error 6 = not found; 29 or HTTP 429 = rate limited; anything else = unavailable.
- Cadence (spec): Last.fm `info` refreshed after 7 days, `top_tags` and `similar` after 90; `not_found` 30 days; `failed` 1 day.
- Tags (spec): at most 20 per subject, weight ≥ 5. Last.fm tags never set `is_genre`.
- Resolution through Last.fm (spec): confidence 0.5, method `lastfm`; accepted when the corrected title is the same title and the corrected artist the same artist (as the reading's or the source's artists), and durations within 5 s when both are known.
- Charts (spec): `global`, `country:BE` (`belgium`), `country:FR` (`france`), `country:US` (`united states`), top 200, once a day. Popularity samples and chart snapshots are never pruned.
- Jobs: queue `enrichment`, `$maxExceptions = 3`, `backoff()` `[60, 600, 3600]`, release on `MetadataSourceRateLimited` for `retryAfter` seconds.

## Review Focus

- **The API key in traces and error messages.** keepsuit's HTTP client instrumentation records the full URL, and only redacts `signature`-like parameters by default; a connection error's message contains the URL. A reasonable person expects the key never to reach Grafana or the `enrichments.error` column (Task 2 tests "keeps the key out of traces" and "keeps the key out of a connection error").
- **A rate limit halfway through a description.** Last.fm answers `info`, then refuses `top_tags`: the released job must not ask `info` again (Task 5 test "resumes after a rate limit without asking again").
- **A name-only recording whose identifier turns up later on another recording.** The resolution moves, the orphan is deleted, nothing is duplicated (Task 4 test "moves to the recording that already holds the identifier").
- **No Last.fm key configured.** Nothing calls Last.fm, and the library progress panel still reaches "done" instead of waiting forever for a source that will never answer (Task 5 test "leaves Last.fm out of the sources without a key"; Task 8 test "takes no chart without a key").
- **A chart snapshot taken twice in a day** (a retried job, a manual run after the schedule): one snapshot only (Task 8 test "takes each chart once a day").

---

## File Structure

```
database/migrations/xxxx_add_lastfm_enrichment.php     schema of the spec, backfill of match_*
app/Support/MusicText.php                              + matchTitle(), matchArtist()
app/Models/Recording.php                               + match_* kept in step, findByName(), isNameOnly()
app/Models/Contributor.php                             + lastfm_listeners, lastfm_playcount
app/Models/Enrichment.php                              + INFO, isDue(), fetchedAt()
app/Models/{PopularitySample,SimilarRecording,SimilarContributor,ChartSnapshot,ChartEntry}.php
app/Enums/EnrichmentStatus.php                         nextAttemptAt(endpoint, from)
app/Enums/ResolutionMethod.php                         + LastFm
app/Services/Metadata/LastFm/{LastFmGateway,HttpLastFmGateway,RateLimitedLastFmGateway,LastFmMapper}.php
app/Services/Metadata/Data/{LastFmTrack,Popularity,SimilarTrack,SimilarArtist,ChartPosition}.php
app/Services/Metadata/CallBudget.php                   + LASTFM
app/Services/Metadata/EnrichmentTelemetry.php (+ OpenTelemetry…, tests/Support/Fake…)  + chartEntries()
app/Actions/ResolveRecordings.php                      Last.fm step, name-only adoption
app/Actions/DescribeRecording.php                      sources(), endpoints(), Last.fm
app/Actions/DescribeContributor.php                    endpoints(), undescribedSources(), Last.fm
app/Actions/ProjectEnrichment.php                      Last.fm tags, similar, popularity, artist
app/Actions/ProjectContributorTags.php → ProjectContributorMetadata.php
app/Actions/SnapshotChart.php
app/Actions/{SummarizeLibraryEnrichment,RecordEnrichmentCoverage,QueueTrackResolution}.php
app/Jobs/{EnrichRecording,EnrichContributor,ProjectRecordingMetadata,ResolveLibraryTracks}.php
app/Jobs/TakeChartSnapshot.php
app/Console/Commands/{TakeChartSnapshotsCommand,RetryDueMetadataCommand,EnrichMetadataCommand}.php
config/services.php, config/opentelemetry.php, routes/console.php, app/Providers/AppServiceProvider.php
tests/Support/FakeLastFmGateway.php, tests/Pest.php, tests/Unit/ArchTest.php
```

---

### Task 1: Schema, match keys and cadence

**Files:**
- Create: `database/migrations/<timestamp>_add_lastfm_enrichment.php`
- Create: `app/Models/PopularitySample.php`, `app/Models/SimilarRecording.php`, `app/Models/SimilarContributor.php`, `app/Models/ChartSnapshot.php`, `app/Models/ChartEntry.php`
- Modify: `app/Support/MusicText.php`, `app/Models/Recording.php`, `app/Models/Contributor.php`, `app/Models/Enrichment.php`, `app/Enums/EnrichmentStatus.php`, `app/Enums/ResolutionMethod.php`
- Test: `tests/Unit/Support/MusicTextTest.php`, `tests/Unit/Models/RecordingTest.php`, `tests/Unit/Models/EnrichmentTest.php`

**Interfaces:**
- Produces: `MusicText::matchTitle(string $title): string`, `MusicText::matchArtist(string $artists): string`; `Recording::findByName(string $title, string $artist): ?Recording`, `Recording->isNameOnly(): bool`, `@property-read string $match_title`, `$match_artist`; `Contributor` `@property-read int|null $lastfm_listeners`, `$lastfm_playcount`; `Enrichment::INFO = 'info'`, `Enrichment::isDue(string $subjectType, string $subjectKey, MetadataSource $source, string $endpoint): bool`, `Enrichment::fetchedAt(…same…): ?CarbonInterface`; `EnrichmentStatus::nextAttemptAt(string $endpoint = '', ?CarbonInterface $from = null): CarbonImmutable`; `ResolutionMethod::LastFm`; models `PopularitySample`, `SimilarRecording`, `SimilarContributor`, `ChartSnapshot` (`entries(): HasMany`), `ChartEntry`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Support/MusicTextTest.php`:

```php
it('builds the title match key without noise but with versions', function (string $title, string $key): void {
    expect(MusicText::matchTitle($title))->toBe($key);
})->with([
    ['Survival (Official Video)', 'survival'],
    ['Mr. Loverman', 'mr loverman'],
    ['Was ist Heir Los (Live)', 'was ist heir los live'],
    ['NICOLE KIDMAN', 'nicole kidman'],
]);

it('builds the artist match key from the first artist without its article', function (string $artists, string $key): void {
    expect(MusicText::matchArtist($artists))->toBe($key);
})->with([
    ['The Bloodhound Gang', 'bloodhound gang'],
    ['Egzod & Maestro Chives', 'egzod'],
    ['ADÉLA', 'adela'],
    ['Muse - Topic', 'muse'],
]);
```

Append to `tests/Unit/Models/RecordingTest.php`:

```php
it('keeps its match key in step with its title and artist', function (): void {
    $recording = Recording::factory()->create(['title' => 'Survival (Official Video)', 'artist_name' => 'The Muse']);

    expect($recording->match_title)->toBe('survival')
        ->and($recording->match_artist)->toBe('muse');

    $recording->update(['title' => 'Uprising']);

    expect($recording->fresh()?->match_title)->toBe('uprising');
});

it('exists without any registry identifier and is then found by name', function (): void {
    $nameOnly = Recording::factory()->create(['mbid' => null, 'isrc' => null, 'title' => 'Still Here', 'artist_name' => 'League of Legends']);
    Recording::factory()->create(['title' => 'Still Here', 'artist_name' => 'League of Legends']);

    expect($nameOnly->isNameOnly())->toBeTrue()
        ->and(Recording::findByName('still here!', 'league of legends')?->id)->toBe($nameOnly->id)
        ->and(Recording::findByName('Other', 'League of Legends'))->toBeNull();
});
```

Append to `tests/Unit/Models/EnrichmentTest.php` (add `use App\Enums\EnrichmentStatus;`, `use App\Enums\MetadataSource;` if missing):

```php
it('refreshes popularity weekly and the rest every ninety days', function (): void {
    expect(EnrichmentStatus::Done->nextAttemptAt(Enrichment::INFO)->toIso8601String())->toBe(now()->addDays(7)->toIso8601String())
        ->and(EnrichmentStatus::Done->nextAttemptAt('top_tags')->toIso8601String())->toBe(now()->addDays(90)->toIso8601String())
        ->and(EnrichmentStatus::NotFound->nextAttemptAt(Enrichment::INFO)->toIso8601String())->toBe(now()->addDays(30)->toIso8601String());
});

it('is due when never asked, failed, or older than its cadence, whatever next_attempt_at says', function (): void {
    expect(Enrichment::isDue(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO))->toBeTrue();

    Enrichment::store(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, ['track' => []]);
    Enrichment::store(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, 'top_tags', EnrichmentStatus::Failed, error: 'boom');
    Enrichment::query()->where('endpoint', Enrichment::INFO)->update(['next_attempt_at' => now()->subYear()]);

    expect(Enrichment::isDue(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO))->toBeFalse()
        ->and(Enrichment::isDue(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, 'top_tags'))->toBeTrue()
        ->and(Enrichment::fetchedAt(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO)?->toIso8601String())->toBe(now()->toIso8601String());

    $this->travel(8)->days();

    expect(Enrichment::isDue(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO))->toBeTrue();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest --tia --parallel tests/Unit/Support/MusicTextTest.php`, then `tests/Unit/Models/RecordingTest.php`, then `tests/Unit/Models/EnrichmentTest.php`.
Expected: FAIL (`matchTitle` undefined, `match_title` column missing, `Enrichment::INFO` undefined).

- [ ] **Step 3: Write the migration**

Run `php artisan make:migration add_lastfm_enrichment --no-interaction`, then:

```php
<?php

declare(strict_types=1);

use App\Support\MusicText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recordings DROP CONSTRAINT recordings_identified');

        Schema::table('recordings', function (Blueprint $table): void {
            $table->string('match_title')->default('');
            $table->string('match_artist')->default('');
            $table->index(['match_title', 'match_artist']);
        });

        DB::table('recordings')->select(['id', 'title', 'artist_name'])->lazyById()->each(function (object $recording): void {
            DB::table('recordings')->where('id', $recording->id)->update([
                'match_title' => MusicText::matchTitle(is_string($recording->title) ? $recording->title : ''),
                'match_artist' => MusicText::matchArtist(is_string($recording->artist_name) ? $recording->artist_name : ''),
            ]);
        });

        Schema::table('contributors', function (Blueprint $table): void {
            $table->unsignedBigInteger('lastfm_listeners')->nullable();
            $table->unsignedBigInteger('lastfm_playcount')->nullable();
        });

        Schema::create('popularity_samples', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('source');
            $table->unsignedBigInteger('listeners');
            $table->unsignedBigInteger('playcount')->nullable();
            $table->timestamp('measured_at');

            $table->index(['subject_type', 'subject_id', 'measured_at']);
        });

        Schema::create('similar_recordings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recording_id')->index()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('artist_name');
            $table->decimal('match', 7, 6);
            $table->string('source');
            $table->timestamps();
        });

        Schema::create('similar_contributors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contributor_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('match', 7, 6);
            $table->string('source');
            $table->timestamps();
        });

        Schema::create('chart_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source');
            $table->string('chart');
            $table->date('taken_on');
            $table->timestamps();

            $table->unique(['source', 'chart', 'taken_on']);
        });

        Schema::create('chart_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('chart_snapshot_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->string('title');
            $table->string('artist_name');
            $table->unsignedBigInteger('listeners')->nullable();
            $table->unsignedBigInteger('playcount')->nullable();
            $table->foreignUuid('recording_id')->nullable()->index()->constrained()->nullOnDelete();

            $table->unique(['chart_snapshot_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_entries');
        Schema::dropIfExists('chart_snapshots');
        Schema::dropIfExists('similar_contributors');
        Schema::dropIfExists('similar_recordings');
        Schema::dropIfExists('popularity_samples');

        Schema::table('contributors', function (Blueprint $table): void {
            $table->dropColumn(['lastfm_listeners', 'lastfm_playcount']);
        });

        Schema::table('recordings', function (Blueprint $table): void {
            $table->dropIndex(['match_title', 'match_artist']);
            $table->dropColumn(['match_title', 'match_artist']);
        });
    }
};
```

`down()` does not restore the CHECK: name-only rows may exist by then.

- [ ] **Step 4: Add the match keys to `MusicText`**

In `app/Support/MusicText.php`, after `sameTitle()`:

```php
    /**
     * The title as recordings are matched across sources: noise removed,
     * version markers ("live", "remix") kept.
     */
    public static function matchTitle(string $title): string
    {
        return self::normalize(self::cleanTitle($title));
    }

    /**
     * The first artist as recordings are matched across sources, without a
     * leading "the".
     */
    public static function matchArtist(string $artists): string
    {
        return self::withoutArticle(self::normalize(self::firstArtist($artists)));
    }
```

- [ ] **Step 5: Update the models and enums**

`app/Models/Recording.php`:
- Class docblock: "A recording known by a registry (MusicBrainz id and/or ISRC) or, failing that, by its normalised title and artist. The seed of plan 2's catalogue `tracks`."
- Add `@property-read string $match_title` and `@property-read string $match_artist` after `$artist_name`.
- Add (with `use App\Support\MusicText;`):

```php
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

    public function isNameOnly(): bool
    {
        return $this->mbid === null && $this->isrc === null;
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
```

`app/Models/Contributor.php`: add `@property-read int|null $lastfm_listeners`, `@property-read int|null $lastfm_playcount`; add both to `$fillable`; add casts `'lastfm_listeners' => 'integer'`, `'lastfm_playcount' => 'integer'` (create `casts()` if absent, following `Recording`).

`app/Enums/ResolutionMethod.php`: add `case LastFm = 'lastfm';`.

`app/Enums/EnrichmentStatus.php`, replace `nextAttemptAt()` (add `use App\Models\Enrichment;`, `use Carbon\CarbonInterface;`):

```php
    /**
     * When to ask again. Popularity readings (`info`) go stale after a week,
     * everything else a source knows after 90 days.
     */
    public function nextAttemptAt(string $endpoint = '', ?CarbonInterface $from = null): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from ?? CarbonImmutable::now());

        return match ($this) {
            self::Done => $from->addDays($endpoint === Enrichment::INFO ? 7 : 90),
            self::NotFound => $from->addDays(30),
            self::Failed => $from->addDay(),
        };
    }
```

`app/Models/Enrichment.php`:
- Add `public const string INFO = 'info';` with a docblock "The endpoint of a popularity reading, refreshed weekly."
- In `store()`, pass the endpoint: `'next_attempt_at' => $status->nextAttemptAt($endpoint),`.
- Add:

```php
    /**
     * Whether a source should be asked about this endpoint again: never
     * answered, last attempt failed, or its answer older than its cadence.
     * `next_attempt_at` is not read: it is moved forward when a job is
     * queued, before the job runs.
     */
    public static function isDue(string $subjectType, string $subjectKey, MetadataSource $source, string $endpoint): bool
    {
        $enrichment = self::query()
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->where('source', $source)
            ->where('endpoint', $endpoint)
            ->first();

        return ! $enrichment instanceof self
            || $enrichment->status === EnrichmentStatus::Failed
            || $enrichment->fetched_at === null
            || $enrichment->status->nextAttemptAt($endpoint, $enrichment->fetched_at)->isPast();
    }

    /**
     * When the last answer of a source about this endpoint was fetched.
     */
    public static function fetchedAt(string $subjectType, string $subjectKey, MetadataSource $source, string $endpoint): ?CarbonInterface
    {
        return self::query()
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->where('source', $source)
            ->where('endpoint', $endpoint)
            ->first()
            ?->fetched_at;
    }
```

- [ ] **Step 6: Create the new models**

Run `php artisan make:model PopularitySample --no-interaction` (and the same for `SimilarRecording`, `SimilarContributor`, `ChartSnapshot`, `ChartEntry`), then write each as below. They are only written by actions, so no factories.

`app/Models/PopularitySample.php`:

```php
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
```

`app/Models/SimilarRecording.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetadataSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A track a source finds similar to a recording, mostly outside the library,
 * so known by its title and artist only.
 *
 * @property-read string $id
 * @property-read string $recording_id
 * @property-read string $title
 * @property-read string $artist_name
 * @property-read float $match
 * @property-read MetadataSource $source
 */
final class SimilarRecording extends Model
{
    use HasUuids;

    protected $fillable = ['recording_id', 'title', 'artist_name', 'match', 'source'];

    /**
     * @return BelongsTo<Recording, $this>
     */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return ['id' => 'string', 'match' => 'float', 'source' => MetadataSource::class];
    }
}
```

`app/Models/SimilarContributor.php`: the same shape with `contributor_id`, `name`, `match`, `source`, a `contributor(): BelongsTo<Contributor, $this>` relation, docblock "An artist a source finds similar to a contributor, known by name only."

```php
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
```

`app/Models/ChartSnapshot.php`:

```php
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
```

`app/Models/ChartEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A track's rank in a chart snapshot, linked to a recording when the library
 * knows it.
 *
 * @property-read string $id
 * @property-read string $chart_snapshot_id
 * @property-read int $rank
 * @property-read string $title
 * @property-read string $artist_name
 * @property-read int|null $listeners
 * @property-read int|null $playcount
 * @property-read string|null $recording_id
 */
final class ChartEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['chart_snapshot_id', 'rank', 'title', 'artist_name', 'listeners', 'playcount', 'recording_id'];

    /**
     * @return BelongsTo<Recording, $this>
     */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class);
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return ['id' => 'string', 'rank' => 'integer', 'listeners' => 'integer', 'playcount' => 'integer'];
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run the three files from Step 2, then `tests/Unit/Actions/ResolveRecordingsTest.php` and `tests/Unit/Actions/ProjectEnrichmentTest.php` (unchanged behaviour).
Expected: PASS.

- [ ] **Step 8: Check the backfill on the local copy of production**

Run: `php artisan migrate --no-interaction`, then with the `database-query` tool: `SELECT count(*) FROM recordings WHERE match_title = '' OR match_artist = ''`.
Expected: `0`.

- [ ] **Step 9: Analyse, format, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze app/Models app/Enums app/Support database/migrations tests/Unit/Models tests/Unit/Support
git add -A database/migrations app/Models app/Enums app/Support tests/Unit/Models tests/Unit/Support
git commit -m "feat(enrichment): let recordings be known by name and store last.fm data"
```

---

### Task 2: Last.fm gateway

**Files:**
- Create: `app/Services/Metadata/LastFm/LastFmGateway.php`, `app/Services/Metadata/LastFm/HttpLastFmGateway.php`, `app/Services/Metadata/LastFm/RateLimitedLastFmGateway.php`, `tests/Support/FakeLastFmGateway.php`
- Modify: `app/Services/Metadata/CallBudget.php`, `app/Providers/AppServiceProvider.php`, `config/services.php`, `config/opentelemetry.php`, `.env.example` (already has the two keys), `tests/Pest.php`, `tests/Unit/ArchTest.php`
- Test: `tests/Unit/Services/Metadata/LastFm/HttpLastFmGatewayTest.php`

**Interfaces:**
- Consumes: `TrackQuery`, `EnrichmentTelemetry::lookup()`, `MetadataSourceRateLimited(MetadataSource, int $retryAfter)`, `MetadataSourceUnavailable(MetadataSource, string $reason)`, `CallBudget::await()`.
- Produces: `LastFmGateway` with `enabled(): bool`, `trackInfo(TrackQuery): ?array`, `trackTopTags(TrackQuery): ?array`, `trackSimilar(TrackQuery): ?array`, `artistInfo(string $name): ?array`, `artistTopTags(string $name): ?array`, `artistSimilar(string $name): ?array`, `topTracks(?string $country): array` (payloads `array<string, mixed>`); `CallBudget::LASTFM = 'lastfm'`; `Tests\Support\FakeLastFmGateway` (constructor `bool $enabled = true`; public arrays `trackInfos`, `trackTopTags`, `trackSimilars` keyed `"artist|title"`, `artistInfos`, `artistTopTags`, `artistSimilars` keyed by name, `topTracks` keyed by country, `''` worldwide; `?Throwable $failure`; `?int $refuseAfterCalls`; `list<string> $calls`).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Services/Metadata/LastFm/HttpLastFmGatewayTest.php`:

```php
<?php

declare(strict_types=1);

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\LastFm\HttpLastFmGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpClientInstrumentation;
use Tests\Support\FakeEnrichmentTelemetry;

beforeEach(function (): void {
    app()->instance(EnrichmentTelemetry::class, new FakeEnrichmentTelemetry());
    config(['services.lastfm.key' => 'test-key']);
});

it('asks for a track by artist and title, corrected, with the key', function (): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response(metadataFixture('lastfm-track-info'))]);

    $payload = resolve(HttpLastFmGateway::class)->trackInfo(new TrackQuery('Mr. Loverman', 'Ricky Montgomery'));

    expect(data_get($payload, 'track.listeners'))->toBe('833437');
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'method') === 'track.getInfo'
        && data_get($request->data(), 'artist') === 'Ricky Montgomery'
        && data_get($request->data(), 'track') === 'Mr. Loverman'
        && data_get($request->data(), 'autocorrect') === '1'
        && data_get($request->data(), 'format') === 'json'
        && data_get($request->data(), 'api_key') === 'test-key');
});

it('returns null when Last.fm does not know the subject', function (): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response(metadataFixture('lastfm-not-found'))]);

    expect(resolve(HttpLastFmGateway::class)->artistInfo('zzqx nonexistent artist'))->toBeNull();
});

it('treats error 29 and HTTP 429 as a rate limit', function (int $status, array $body): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response($body, $status)]);

    expect(fn (): ?array => resolve(HttpLastFmGateway::class)->trackTopTags(new TrackQuery('Loreley', 'Lord of the Lost')))
        ->toThrow(MetadataSourceRateLimited::class);
})->with([
    [200, ['error' => 29, 'message' => 'Rate Limit Exceeded']],
    [429, []],
]);

it('treats other errors as unavailable', function (int $status, array $body): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response($body, $status)]);

    expect(fn (): ?array => resolve(HttpLastFmGateway::class)->artistSimilar('Lord of the Lost'))
        ->toThrow(MetadataSourceUnavailable::class);
})->with([
    [200, ['error' => 11, 'message' => 'Service Offline']],
    [200, ['error' => 10, 'message' => 'Invalid API key']],
    [500, []],
]);

it('keeps the key out of a connection error', function (): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::failedConnection('cURL error 28 for https://ws.audioscrobbler.com/2.0/?api_key=test-key')]);

    try {
        resolve(HttpLastFmGateway::class)->trackInfo(new TrackQuery('Loreley', 'Lord of the Lost'));
        $this->fail('Expected the call to fail.');
    } catch (MetadataSourceUnavailable $exception) {
        expect($exception->getMessage())->not->toContain('test-key');
    }
});

it('asks for the charts worldwide and per country, top 200', function (): void {
    Http::fake(['ws.audioscrobbler.com/*' => Http::response(metadataFixture('lastfm-chart-top-tracks'))]);
    $gateway = resolve(HttpLastFmGateway::class);

    $gateway->topTracks(null);
    $gateway->topTracks('belgium');

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'method') === 'chart.getTopTracks' && data_get($request->data(), 'limit') === '200');
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'method') === 'geo.getTopTracks' && data_get($request->data(), 'country') === 'belgium');
});

it('is enabled only with a key', function (): void {
    expect(resolve(HttpLastFmGateway::class)->enabled())->toBeTrue();

    config(['services.lastfm.key' => '']);

    expect(resolve(HttpLastFmGateway::class)->enabled())->toBeFalse();
});

it('keeps the key out of traces', function (): void {
    expect(config('opentelemetry.instrumentation.'.HttpClientInstrumentation::class.'.sensitive_query_parameters'))->toContain('api_key');
});
```

Before writing this test, check the namespace of `HttpClientInstrumentation` in `config/opentelemetry.php` (`use … Instrumentation;`) and import that exact class.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest --tia --parallel tests/Unit/Services/Metadata/LastFm/HttpLastFmGatewayTest.php`
Expected: FAIL (class `HttpLastFmGateway` not found).

- [ ] **Step 3: Configure the source**

`config/services.php`, after `musicbrainz`:

```php
    'lastfm' => [
        'url' => env('LASTFM_URL', 'https://ws.audioscrobbler.com/2.0/'),
        'key' => env('LASTFM_API_KEY', ''),
        'shared_secret' => env('LASTFM_API_SHARED_SECRET', ''),
    ],
```

`config/opentelemetry.php`, in the `HttpClientInstrumentation` block: `'sensitive_query_parameters' => ['api_key'],`.

`app/Services/Metadata/CallBudget.php`: add `public const string LASTFM = 'lastfm';` after `MUSICBRAINZ`.

`app/Providers/AppServiceProvider.php`:
- in `boot()`, after the MusicBrainz limiter (extend its comment: "Last.fm allows five requests a second averaged over five minutes."):

```php
        RateLimiter::for(CallBudget::LASTFM, fn (string $key): array => [
            Limit::perSecond(4)->by('lastfm:second:'.$key),
        ]);
```

- in `register()`, after the MusicBrainz binding:

```php
        $this->app->bind(LastFmGateway::class, fn (): LastFmGateway => new RateLimitedLastFmGateway(
            $this->app->make(HttpLastFmGateway::class),
            $this->app->make(CallBudget::class),
        ));
```

- [ ] **Step 4: Write the contract**

`app/Services/Metadata/LastFm/LastFmGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\LastFm;

use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Raw calls to the Last.fm API (JSON, autocorrected). Every call may throw
 * MetadataSourceRateLimited or MetadataSourceUnavailable; null means Last.fm
 * does not know the subject.
 */
interface LastFmGateway
{
    /**
     * Whether an API key is configured. Without one, nothing asks Last.fm.
     */
    public function enabled(): bool;

    /**
     * Corrected title and artist, duration, listeners, play count.
     *
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function trackInfo(TrackQuery $query): ?array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function trackTopTags(TrackQuery $query): ?array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function trackSimilar(TrackQuery $query): ?array;

    /**
     * Listeners, play count, a few tags and similar artists.
     *
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function artistInfo(string $name): ?array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function artistTopTags(string $name): ?array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function artistSimilar(string $name): ?array;

    /**
     * The 200 most listened tracks, worldwide or in one country (its
     * English name, as `belgium`).
     *
     * @return array<string, mixed>
     *
     * @throws MetadataSourceRateLimited
     * @throws MetadataSourceUnavailable
     */
    public function topTracks(?string $country): array;
}
```

- [ ] **Step 5: Write the HTTP gateway**

`app/Services/Metadata/LastFm/HttpLastFmGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\LastFm;

use App\Enums\LookupOutcome;
use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Last.fm answers errors with HTTP 200 and an `error` code: 6 is "not
 * found", 29 the rate limit. The key travels in the query string, so no
 * message built here ever contains the URL.
 */
final readonly class HttpLastFmGateway implements LastFmGateway
{
    private const int NOT_FOUND = 6;

    private const int RATE_LIMITED = 29;

    private const int SIMILAR_LIMIT = 50;

    private const int CHART_LIMIT = 200;

    public function __construct(private EnrichmentTelemetry $telemetry) {}

    public function enabled(): bool
    {
        return config()->string('services.lastfm.key') !== '';
    }

    public function trackInfo(TrackQuery $query): ?array
    {
        return $this->call('track_info', 'track.getInfo', ['artist' => $query->artist, 'track' => $query->title]);
    }

    public function trackTopTags(TrackQuery $query): ?array
    {
        return $this->call('track_top_tags', 'track.getTopTags', ['artist' => $query->artist, 'track' => $query->title]);
    }

    public function trackSimilar(TrackQuery $query): ?array
    {
        return $this->call('track_similar', 'track.getSimilar', ['artist' => $query->artist, 'track' => $query->title, 'limit' => self::SIMILAR_LIMIT]);
    }

    public function artistInfo(string $name): ?array
    {
        return $this->call('artist_info', 'artist.getInfo', ['artist' => $name]);
    }

    public function artistTopTags(string $name): ?array
    {
        return $this->call('artist_top_tags', 'artist.getTopTags', ['artist' => $name]);
    }

    public function artistSimilar(string $name): ?array
    {
        return $this->call('artist_similar', 'artist.getSimilar', ['artist' => $name, 'limit' => self::SIMILAR_LIMIT]);
    }

    public function topTracks(?string $country): array
    {
        $payload = $country === null
            ? $this->call('chart', 'chart.getTopTracks', ['limit' => self::CHART_LIMIT])
            : $this->call('geo', 'geo.getTopTracks', ['country' => $country, 'limit' => self::CHART_LIMIT]);

        return $payload ?? ['tracks' => ['track' => []]];
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>|null
     */
    private function call(string $endpoint, string $method, array $query): ?array
    {
        $started = microtime(true);

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->get(config()->string('services.lastfm.url'), [
                    ...$query,
                    'method' => $method,
                    'api_key' => config()->string('services.lastfm.key'),
                    'format' => 'json',
                    'autocorrect' => 1,
                ]);
        } catch (ConnectionException) {
            $this->telemetry->lookup(MetadataSource::LastFm, $endpoint, LookupOutcome::Failed, microtime(true) - $started);

            throw new MetadataSourceUnavailable(MetadataSource::LastFm, "connection failed on {$endpoint}");
        }

        $seconds = microtime(true) - $started;
        $payload = $response->json();
        $error = is_array($payload) && is_int($payload['error'] ?? null) ? $payload['error'] : null;

        if ($response->status() === 429 || $error === self::RATE_LIMITED) {
            throw new MetadataSourceRateLimited(MetadataSource::LastFm, max(1, (int) $response->header('Retry-After') ?: 60));
        }

        if ($error === self::NOT_FOUND) {
            $this->telemetry->lookup(MetadataSource::LastFm, $endpoint, LookupOutcome::NotFound, $seconds);

            return null;
        }

        if (! $response->successful() || ! is_array($payload) || $error !== null) {
            $this->telemetry->lookup(MetadataSource::LastFm, $endpoint, LookupOutcome::Failed, $seconds);

            throw new MetadataSourceUnavailable(MetadataSource::LastFm, $error === null ? "HTTP {$response->status()} on {$endpoint}" : "error {$error} on {$endpoint}");
        }

        $this->telemetry->lookup(MetadataSource::LastFm, $endpoint, LookupOutcome::Found, $seconds);

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
```

(The `@var` on the returned payload is the existing pattern of `HttpMusicBrainzGateway`.)

- [ ] **Step 6: Write the rate-limited decorator**

`app/Services/Metadata/LastFm/RateLimitedLastFmGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\LastFm;

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\CallBudget;
use App\Services\Metadata\Data\TrackQuery;

/**
 * Spends the `lastfm` budget (four calls a second) before every call,
 * sleeping through the wait for the next second.
 */
final readonly class RateLimitedLastFmGateway implements LastFmGateway
{
    public function __construct(
        private LastFmGateway $gateway,
        private CallBudget $budget,
    ) {}

    public function enabled(): bool
    {
        return $this->gateway->enabled();
    }

    public function trackInfo(TrackQuery $query): ?array
    {
        $this->spend();

        return $this->gateway->trackInfo($query);
    }

    public function trackTopTags(TrackQuery $query): ?array
    {
        $this->spend();

        return $this->gateway->trackTopTags($query);
    }

    public function trackSimilar(TrackQuery $query): ?array
    {
        $this->spend();

        return $this->gateway->trackSimilar($query);
    }

    public function artistInfo(string $name): ?array
    {
        $this->spend();

        return $this->gateway->artistInfo($name);
    }

    public function artistTopTags(string $name): ?array
    {
        $this->spend();

        return $this->gateway->artistTopTags($name);
    }

    public function artistSimilar(string $name): ?array
    {
        $this->spend();

        return $this->gateway->artistSimilar($name);
    }

    public function topTracks(?string $country): array
    {
        $this->spend();

        return $this->gateway->topTracks($country);
    }

    private function spend(): void
    {
        $wait = $this->budget->await(CallBudget::LASTFM);

        if ($wait !== null) {
            throw new MetadataSourceRateLimited(MetadataSource::LastFm, $wait);
        }
    }
}
```

- [ ] **Step 7: Write the fake and bind it for every test**

`tests/Support/FakeLastFmGateway.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\MetadataSource;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\LastFm\LastFmGateway;
use Throwable;

final class FakeLastFmGateway implements LastFmGateway
{
    /** @var array<string, array<string, mixed>> "artist|title" => payload */
    public array $trackInfos = [];

    /** @var array<string, array<string, mixed>> "artist|title" => payload */
    public array $trackTopTags = [];

    /** @var array<string, array<string, mixed>> "artist|title" => payload */
    public array $trackSimilars = [];

    /** @var array<string, array<string, mixed>> name => payload */
    public array $artistInfos = [];

    /** @var array<string, array<string, mixed>> name => payload */
    public array $artistTopTags = [];

    /** @var array<string, array<string, mixed>> name => payload */
    public array $artistSimilars = [];

    /** @var array<string, array<string, mixed>> country ('' worldwide) => payload */
    public array $topTracks = [];

    public ?Throwable $failure = null;

    /** Refuses every call after this many, like Sonder's own budget. */
    public ?int $refuseAfterCalls = null;

    /** @var list<string> */
    public array $calls = [];

    public function __construct(public bool $enabled = true) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function trackInfo(TrackQuery $query): ?array
    {
        return $this->answer("track_info:{$query->artist}|{$query->title}", $this->trackInfos["{$query->artist}|{$query->title}"] ?? null);
    }

    public function trackTopTags(TrackQuery $query): ?array
    {
        return $this->answer("track_top_tags:{$query->artist}|{$query->title}", $this->trackTopTags["{$query->artist}|{$query->title}"] ?? null);
    }

    public function trackSimilar(TrackQuery $query): ?array
    {
        return $this->answer("track_similar:{$query->artist}|{$query->title}", $this->trackSimilars["{$query->artist}|{$query->title}"] ?? null);
    }

    public function artistInfo(string $name): ?array
    {
        return $this->answer("artist_info:{$name}", $this->artistInfos[$name] ?? null);
    }

    public function artistTopTags(string $name): ?array
    {
        return $this->answer("artist_top_tags:{$name}", $this->artistTopTags[$name] ?? null);
    }

    public function artistSimilar(string $name): ?array
    {
        return $this->answer("artist_similar:{$name}", $this->artistSimilars[$name] ?? null);
    }

    public function topTracks(?string $country): array
    {
        return $this->answer('top_tracks:'.($country ?? ''), $this->topTracks[$country ?? ''] ?? ['tracks' => ['track' => []]]) ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function answer(string $call, ?array $payload): ?array
    {
        if ($this->refuseAfterCalls !== null && count($this->calls) >= $this->refuseAfterCalls) {
            throw new MetadataSourceRateLimited(MetadataSource::LastFm, 1);
        }

        $this->calls[] = $call;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $payload;
    }
}
```

`tests/Pest.php`: import `App\Services\Metadata\LastFm\LastFmGateway` and `Tests\Support\FakeLastFmGateway`, and in `beforeEach`, after the MusicBrainz line:

```php
        // Off by default so every other test keeps its sources; Last.fm tests bind an enabled fake.
        app()->instance(LastFmGateway::class, new FakeLastFmGateway(enabled: false));
```

`tests/Unit/ArchTest.php`: add `'App\Services\Metadata\LastFm\HttpLastFmGateway'` and `'App\Services\Metadata\LastFm\RateLimitedLastFmGateway'` to the "actions reach metadata services through their gateway contracts" list, and `'App\Services\Metadata\LastFm\HttpLastFmGateway'` to the `ignoring` list of "only the metadata gateways call metadata services over HTTP".

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest --tia --parallel tests/Unit/Services/Metadata/LastFm/HttpLastFmGatewayTest.php`, then `tests/Unit/ArchTest.php`, then `tests/Unit/Services/CallBudgetTest.php`.
Expected: PASS.

- [ ] **Step 9: Analyse, format, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze app/Services/Metadata app/Providers config tests/Support tests/Pest.php tests/Unit/ArchTest.php tests/Unit/Services/Metadata/LastFm
git add app/Services/Metadata app/Providers config tests/Support tests/Pest.php tests/Unit/ArchTest.php tests/Unit/Services/Metadata/LastFm .env.example
git commit -m "feat(enrichment): read tags, similar and popularity from last.fm"
```

---

### Task 3: Last.fm mapper

**Files:**
- Create: `app/Services/Metadata/LastFm/LastFmMapper.php`, `app/Services/Metadata/Data/LastFmTrack.php`, `Popularity.php`, `SimilarTrack.php`, `SimilarArtist.php`, `ChartPosition.php`
- Test: `tests/Unit/Services/Metadata/LastFm/LastFmMapperTest.php`

**Interfaces:**
- Consumes: `WeightedTag(string $name, int $weight, bool $isGenre)`, `MusicText::tagSlug()`.
- Produces (all static on `LastFmMapper`, payloads `array<string, mixed>`): `track(array): ?LastFmTrack`, `popularity(array): ?Popularity`, `tags(array): list<WeightedTag>`, `similarTracks(array): list<SimilarTrack>`, `similarArtists(array): list<SimilarArtist>`, `chart(array): list<ChartPosition>`; constants `MAX_TAGS = 20`, `MIN_TAG_WEIGHT = 5`. Data: `LastFmTrack(string $title, string $artist, ?int $durationSeconds)`, `Popularity(int $listeners, ?int $playcount)`, `SimilarTrack(string $title, string $artist, float $match)`, `SimilarArtist(string $name, float $match)`, `ChartPosition(int $rank, string $title, string $artist, ?int $listeners, ?int $playcount)`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Services/Metadata/LastFm/LastFmMapperTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Metadata\Data\ChartPosition;
use App\Services\Metadata\Data\SimilarArtist;
use App\Services\Metadata\Data\SimilarTrack;
use App\Services\Metadata\Data\WeightedTag;
use App\Services\Metadata\LastFm\LastFmMapper;

it('reads the corrected track and its duration in milliseconds', function (): void {
    $track = LastFmMapper::track(metadataFixture('lastfm-track-info'));

    expect($track?->title)->toBe('Mr. Loverman')
        ->and($track?->artist)->toBe('Ricky Montgomery')
        ->and($track?->durationSeconds)->toBe(216)
        ->and(LastFmMapper::track(metadataFixture('lastfm-track-info-without-duration'))?->durationSeconds)->toBeNull()
        ->and(LastFmMapper::track(metadataFixture('lastfm-not-found')))->toBeNull();
});

it('reads the popularity of a track and of an artist', function (): void {
    $track = LastFmMapper::popularity(metadataFixture('lastfm-track-info'));
    $artist = LastFmMapper::popularity(metadataFixture('lastfm-artist-info'));

    expect([$track?->listeners, $track?->playcount])->toBe([833437, 10346073])
        ->and([$artist?->listeners, $artist?->playcount])->toBe([171227, 8932613]);
});

it('keeps the strongest tags, weight five and over', function (): void {
    $names = fn (array $tags): array => array_map(fn (WeightedTag $tag): string => $tag->name, $tags);

    expect($names(LastFmMapper::tags(metadataFixture('lastfm-track-top-tags'))))->toBe(['banana fish', 'indie', 'pop', 'indie pop', 'ash', 'indie rock', 'eiji'])
        ->and($names(LastFmMapper::tags(metadataFixture('lastfm-artist-top-tags'))))->toBe(['gothic rock', 'gothic metal', 'german', 'glam rock', 'industrial metal', 'gothic'])
        ->and(LastFmMapper::tags(metadataFixture('lastfm-artist-top-tags'))[1]->weight)->toBe(79)
        ->and(LastFmMapper::tags(metadataFixture('lastfm-artist-top-tags'))[0]->isGenre)->toBeFalse()
        ->and(LastFmMapper::tags(metadataFixture('lastfm-track-top-tags-empty')))->toBe([]);
});

it('caps the tags at twenty and folds spellings of the same tag', function (): void {
    $tags = array_map(fn (int $index): array => ['name' => "tag {$index}", 'count' => 100 - $index], range(1, 30));
    $tags[] = ['name' => 'Hip-Hop', 'count' => 99];
    array_unshift($tags, ['name' => 'hip hop', 'count' => 100]);

    $mapped = LastFmMapper::tags(['toptags' => ['tag' => $tags]]);

    expect($mapped)->toHaveCount(20)
        ->and(array_filter($mapped, fn (WeightedTag $tag): bool => $tag->name === 'hip-hop'))->toBe([]);
});

it('reads similar tracks and artists with their match', function (): void {
    $tracks = LastFmMapper::similarTracks(metadataFixture('lastfm-track-similar'));
    $artists = LastFmMapper::similarArtists(metadataFixture('lastfm-artist-similar'));

    expect($tracks)->toHaveCount(5)
        ->and($tracks[0])->toEqual(new SimilarTrack('Six Feet Underground', 'Lord of the Lost', 1.0))
        ->and($artists)->toHaveCount(5)
        ->and($artists[0])->toEqual(new SimilarArtist('Chris Harms', 1.0));
});

it('ranks chart entries by their position', function (): void {
    $global = LastFmMapper::chart(metadataFixture('lastfm-chart-top-tracks'));
    $belgium = LastFmMapper::chart(metadataFixture('lastfm-geo-top-tracks'));

    expect($global)->toHaveCount(5)
        ->and($global[0])->toEqual(new ChartPosition(1, 'NICOLE KIDMAN', 'ADÉLA', 191737, 2056383))
        ->and($global[4]->rank)->toBe(5)
        ->and($belgium[0])->toEqual(new ChartPosition(1, "Ain't In LA", 'ADÉLA', 455, null));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest --tia --parallel tests/Unit/Services/Metadata/LastFm/LastFmMapperTest.php`
Expected: FAIL (class `LastFmMapper` not found).

- [ ] **Step 3: Write the Data objects**

Each in `app/Services/Metadata/Data/`, `final readonly class` with a one-line docblock:

```php
/**
 * A track as Last.fm corrected it. Last.fm often does not know the length.
 */
final readonly class LastFmTrack
{
    public function __construct(
        public string $title,
        public string $artist,
        public ?int $durationSeconds,
    ) {}
}
```

```php
/**
 * How many people listened, and how many times, at the time of reading.
 */
final readonly class Popularity
{
    public function __construct(
        public int $listeners,
        public ?int $playcount,
    ) {}
}
```

```php
/**
 * A track a source finds similar, with its match from 0 to 1.
 */
final readonly class SimilarTrack
{
    public function __construct(
        public string $title,
        public string $artist,
        public float $match,
    ) {}
}
```

```php
/**
 * An artist a source finds similar, with its match from 0 to 1.
 */
final readonly class SimilarArtist
{
    public function __construct(
        public string $name,
        public float $match,
    ) {}
}
```

```php
/**
 * A track's rank in a chart (1 is the top).
 */
final readonly class ChartPosition
{
    public function __construct(
        public int $rank,
        public string $title,
        public string $artist,
        public ?int $listeners,
        public ?int $playcount,
    ) {}
}
```

- [ ] **Step 4: Write the mapper**

`app/Services/Metadata/LastFm/LastFmMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Metadata\LastFm;

use App\Services\Metadata\Data\ChartPosition;
use App\Services\Metadata\Data\LastFmTrack;
use App\Services\Metadata\Data\Popularity;
use App\Services\Metadata\Data\SimilarArtist;
use App\Services\Metadata\Data\SimilarTrack;
use App\Services\Metadata\Data\WeightedTag;
use App\Support\MusicText;

/**
 * Turns Last.fm payloads into Data objects. Last.fm sends numbers as
 * strings, durations in milliseconds in `getInfo` (often "0") but in seconds
 * elsewhere, and tags as a folksonomy: they are never treated as genres.
 */
final class LastFmMapper
{
    public const int MAX_TAGS = 20;

    public const int MIN_TAG_WEIGHT = 5;

    /**
     * @param  array<string, mixed>  $payload  a track.getInfo answer
     */
    public static function track(array $payload): ?LastFmTrack
    {
        $track = self::map($payload['track'] ?? null);
        $title = self::string($track['name'] ?? null);
        $artist = self::string(self::map($track['artist'] ?? null)['name'] ?? null);

        if ($title === null || $artist === null) {
            return null;
        }

        $milliseconds = self::integer($track['duration'] ?? null) ?? 0;

        return new LastFmTrack($title, $artist, $milliseconds > 0 ? intdiv($milliseconds + 500, 1000) : null);
    }

    /**
     * @param  array<string, mixed>  $payload  a track.getInfo or artist.getInfo answer
     */
    public static function popularity(array $payload): ?Popularity
    {
        $subject = self::map($payload['track'] ?? $payload['artist'] ?? null);
        $stats = isset($subject['stats']) ? self::map($subject['stats']) : $subject;
        $listeners = self::integer($stats['listeners'] ?? null);

        return $listeners === null ? null : new Popularity($listeners, self::integer($stats['playcount'] ?? null));
    }

    /**
     * The strongest tags, at most MAX_TAGS, weight MIN_TAG_WEIGHT and over;
     * two spellings of one tag keep the first.
     *
     * @param  array<string, mixed>  $payload  a getTopTags answer
     * @return list<WeightedTag>
     */
    public static function tags(array $payload): array
    {
        $tags = [];

        foreach (self::rows(self::map($payload['toptags'] ?? null)['tag'] ?? null) as $tag) {
            $name = mb_strtolower(self::string($tag['name'] ?? null) ?? '');
            $weight = min(100, self::integer($tag['count'] ?? null) ?? 0);
            $slug = MusicText::tagSlug($name);

            if ($slug !== '' && $weight >= self::MIN_TAG_WEIGHT && ! isset($tags[$slug])) {
                $tags[$slug] = new WeightedTag($name, $weight, false);
            }
        }

        $tags = array_values($tags);
        usort($tags, fn (WeightedTag $a, WeightedTag $b): int => $b->weight <=> $a->weight);

        return array_slice($tags, 0, self::MAX_TAGS);
    }

    /**
     * @param  array<string, mixed>  $payload  a track.getSimilar answer
     * @return list<SimilarTrack>
     */
    public static function similarTracks(array $payload): array
    {
        $similar = [];

        foreach (self::rows(self::map($payload['similartracks'] ?? null)['track'] ?? null) as $track) {
            $title = self::string($track['name'] ?? null);
            $artist = self::string(self::map($track['artist'] ?? null)['name'] ?? null);

            if ($title !== null && $artist !== null) {
                $similar[] = new SimilarTrack($title, $artist, self::match($track['match'] ?? null));
            }
        }

        return $similar;
    }

    /**
     * @param  array<string, mixed>  $payload  an artist.getSimilar answer
     * @return list<SimilarArtist>
     */
    public static function similarArtists(array $payload): array
    {
        $similar = [];

        foreach (self::rows(self::map($payload['similarartists'] ?? null)['artist'] ?? null) as $artist) {
            $name = self::string($artist['name'] ?? null);

            if ($name !== null) {
                $similar[] = new SimilarArtist($name, self::match($artist['match'] ?? null));
            }
        }

        return $similar;
    }

    /**
     * @param  array<string, mixed>  $payload  a chart.getTopTracks or geo.getTopTracks answer
     * @return list<ChartPosition>
     */
    public static function chart(array $payload): array
    {
        $positions = [];

        foreach (self::rows(self::map($payload['tracks'] ?? null)['track'] ?? null) as $index => $track) {
            $title = self::string($track['name'] ?? null);
            $artist = self::string(self::map($track['artist'] ?? null)['name'] ?? null);

            if ($title !== null && $artist !== null) {
                $positions[] = new ChartPosition($index + 1, $title, $artist, self::integer($track['listeners'] ?? null), self::integer($track['playcount'] ?? null));
            }
        }

        return $positions;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function rows(mixed $value): array
    {
        return array_values(array_filter(self::map($value), is_array(...)));
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function match(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.0;
    }
}
```

If PHPStan types `array_filter(…, is_array(...))` too loosely for `rows()`, write the loop explicitly:

```php
        $rows = [];

        foreach (self::map($value) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest --tia --parallel tests/Unit/Services/Metadata/LastFm/LastFmMapperTest.php`
Expected: PASS.

- [ ] **Step 6: Analyse, format, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze app/Services/Metadata tests/Unit/Services/Metadata/LastFm
git add app/Services/Metadata tests/Unit/Services/Metadata/LastFm
git commit -m "feat(enrichment): map last.fm answers into tags, similar and charts"
```

---

### Task 4: Resolution through Last.fm

**Files:**
- Modify: `app/Actions/ResolveRecordings.php`
- Test: `tests/Unit/Actions/ResolveRecordingsTest.php`

**Interfaces:**
- Consumes: `LastFmGateway::enabled()`, `::trackInfo()`, `LastFmMapper::track()`, `Recording::findByName()`, `->isNameOnly()`, `ResolutionMethod::LastFm`, `Enrichment::INFO`.
- Produces: unchanged `ResolveRecordings::handle(list<TrackToResolve>, ?CarbonInterface): list<Recording>`; name-only recordings; a recording's `lastfm`/`info` enrichment stored at resolution.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Actions/ResolveRecordingsTest.php`, import `App\Enums\MetadataSource`, `App\Models\Enrichment`, `App\Services\Metadata\LastFm\LastFmGateway`, `Tests\Support\FakeLastFmGateway`; in `beforeEach` add:

```php
    $this->lastFm = new FakeLastFmGateway();
    app()->instance(LastFmGateway::class, $this->lastFm);
```

Then append:

```php
/**
 * What Last.fm answers about Survival, its length in milliseconds.
 *
 * @return array<string, mixed>
 */
function lastFmSurvival(string $artist = 'Muse', string $duration = '258000'): array
{
    return ['track' => ['name' => 'Survival', 'duration' => $duration, 'listeners' => '1000', 'playcount' => '5000', 'artist' => ['name' => $artist]]];
}

it('resolves through Last.fm what the registries miss', function (): void {
    $this->lastFm->trackInfos['Muse|Survival'] = lastFmSurvival();

    $recordings = resolve(ResolveRecordings::class)->handle([survival()]);
    $resolution = RecordingResolution::query()->sole();

    expect($recordings)->toHaveCount(1)
        ->and($recordings[0]->isNameOnly())->toBeTrue()
        ->and($recordings[0]->match_title)->toBe('survival')
        ->and($resolution->status)->toBe(ResolutionStatus::Resolved)
        ->and($resolution->method)->toBe(ResolutionMethod::LastFm)
        ->and((float) $resolution->confidence)->toBe(0.5)
        ->and(Enrichment::payloadFor(Enrichment::RECORDING, $recordings[0]->id, MetadataSource::LastFm, Enrichment::INFO))->toBe(lastFmSurvival());
});

it('refuses a Last.fm answer about another artist or a length too far off', function (array $answer, ResolutionStatus $status): void {
    $this->lastFm->trackInfos['Muse|Survival'] = $answer;

    resolve(ResolveRecordings::class)->handle([survival()]);

    expect(RecordingResolution::query()->sole()->status)->toBe($status);
})->with([
    'another artist' => [lastFmSurvival(artist: 'Muse Tribute Band'), ResolutionStatus::NotFound],
    'too long' => [lastFmSurvival(duration: '300000'), ResolutionStatus::NotFound],
    'unknown length' => [lastFmSurvival(duration: '0'), ResolutionStatus::Resolved],
]);

it('does not ask Last.fm without a key', function (): void {
    $this->lastFm->enabled = false;

    resolve(ResolveRecordings::class)->handle([survival()]);

    expect($this->lastFm->calls)->toBe([])
        ->and(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::NotFound);
});

it('reuses the name-only recording for another video of the same song', function (): void {
    $this->lastFm->trackInfos['Muse|Survival'] = lastFmSurvival();
    $live = new TrackToResolve(Provider::YouTubeMusic, 'otherVideo1', 'Survival', 'Muse', 258);

    $first = resolve(ResolveRecordings::class)->handle([survival()]);
    $second = resolve(ResolveRecordings::class)->handle([$live]);

    expect($second[0]->id)->toBe($first[0]->id)
        ->and(Recording::query()->count())->toBe(1);
});

it('gives a name-only recording the identifiers found later', function (): void {
    $this->lastFm->trackInfos['Muse|Survival'] = lastFmSurvival();
    $nameOnly = resolve(ResolveRecordings::class)->handle([survival()])[0];
    $this->travel(2)->days();
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    $recording = resolve(ResolveRecordings::class)->handle([survival()])[0];

    expect($recording->id)->toBe($nameOnly->id)
        ->and($recording->mbid)->toBe('464d783d-1be7-4e1c-a75b-2b568eb20454')
        ->and(RecordingResolution::query()->sole()->method)->toBe(ResolutionMethod::CreditsFm)
        ->and(Recording::query()->count())->toBe(1);
});

it('moves to the recording that already holds the identifier and drops the orphan', function (): void {
    $this->lastFm->trackInfos['Muse|Survival'] = lastFmSurvival();
    $nameOnly = resolve(ResolveRecordings::class)->handle([survival()])[0];
    $identified = Recording::factory()->create(['mbid' => '464d783d-1be7-4e1c-a75b-2b568eb20454', 'isrc' => 'GBAHT1200434']);
    $this->travel(2)->days();
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    $recording = resolve(ResolveRecordings::class)->handle([survival()])[0];

    expect($recording->id)->toBe($identified->id)
        ->and(Recording::query()->find($nameOnly->id))->toBeNull();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest --tia --parallel tests/Unit/Actions/ResolveRecordingsTest.php`
Expected: the six new tests FAIL (tracks stay `not_found`); the existing ones PASS.

- [ ] **Step 3: Add the Last.fm step**

In `app/Actions/ResolveRecordings.php`:
- Constructor: add `private LastFmGateway $lastFm,` after `$musicBrainz`. Imports: `App\Services\Metadata\LastFm\LastFmGateway`, `App\Services\Metadata\LastFm\LastFmMapper`, `App\Services\Metadata\Data\LastFmTrack`.
- Update the `handle()` docblock: "…then asks credits.fm about the others… A track the registries miss is looked up on Last.fm last, and becomes a recording known by name."
- In `resolve()`, between the search loop and `$this->recordMiss(…)`:

```php
        if ($this->lastFm->enabled()) {
            foreach ($candidates as $candidate) {
                $known = $this->knownToLastFm($track, $candidate['query']);

                if ($known !== null) {
                    return $this->recordByName($track, $candidate['query'], $known['track'], $known['payload']);
                }
            }
        }
```

- Add:

```php
    /**
     * Last.fm's corrected track when it is the same song: same title, same
     * artist, and lengths within the tolerance when both are known.
     *
     * @return array{track: LastFmTrack, payload: array<string, mixed>}|null
     */
    private function knownToLastFm(TrackToResolve $track, TrackQuery $query): ?array
    {
        $payload = $this->ask($track, MetadataSource::LastFm, "track_info:{$query->artist}|{$query->title}", fn (): ?array => $this->lastFm->trackInfo($query));
        $known = $payload === null ? null : LastFmMapper::track($payload);

        if ($payload === null
            || ! $known instanceof LastFmTrack
            || ! MusicText::sameTitle($known->title, $query->title)
            || ! (MusicText::sameArtist($known->artist, $query->artist) || MusicText::sameArtist($known->artist, $track->artists))
            || ! $this->sameDuration($track->durationSeconds, $known->durationSeconds)) {
            return null;
        }

        return ['track' => $known, 'payload' => $payload];
    }

    /**
     * Resolves to the recording known by Last.fm's names, created when new,
     * and keeps Last.fm's answer as its first popularity reading.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordByName(TrackToResolve $track, TrackQuery $query, LastFmTrack $known, array $payload): Recording
    {
        $recording = Recording::findByName($known->title, $known->artist)
            ?? Recording::query()->create([
                'title' => $known->title,
                'artist_name' => $known->artist,
                'duration_seconds' => $known->durationSeconds ?? $track->durationSeconds,
            ]);

        $this->resolveTo($track, $query, ResolutionMethod::LastFm, 0.5, $recording);
        Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, $payload);

        return $recording;
    }
```

- [ ] **Step 4: Adopt or drop name-only recordings when identifiers turn up**

Replace `record()` by `record()` plus `resolveTo()`:

```php
    private function record(TrackToResolve $track, TrackQuery $query, ResolutionMethod $method, float $confidence, ?RegistryRecording $registry, ?string $isrc): Recording
    {
        $current = RecordingResolution::query()
            ->where('provider', $track->provider)
            ->where('external_id', $track->externalId)
            ->first()
            ?->recording;
        $nameOnly = $current instanceof Recording && $current->isNameOnly() ? $current : null;

        $recording = $this->recordingFor($registry, $isrc, $query, $track->durationSeconds, $nameOnly);
        $this->resolveTo($track, $query, $method, $confidence, $recording);

        if ($nameOnly instanceof Recording && $nameOnly->id !== $recording->id && $nameOnly->resolutions()->doesntExist()) {
            $nameOnly->delete();
        }

        return $recording;
    }

    private function resolveTo(TrackToResolve $track, TrackQuery $query, ResolutionMethod $method, float $confidence, Recording $recording): void
    {
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
    }
```

Change `recordingFor()` to adopt the name-only recording where it would otherwise create one:

```php
    /**
     * By MusicBrainz id first; otherwise adopts a recording known only by
     * this ISRC, or the track's name-only recording; otherwise creates one.
     */
    private function recordingFor(?RegistryRecording $registry, ?string $isrc, TrackQuery $query, ?int $duration, ?Recording $nameOnly): Recording
    {
        $values = [
            'title' => $registry->title ?? $query->title,
            'artist_name' => $registry->artists[0]['name'] ?? $query->artist,
            'duration_seconds' => $registry->durationSeconds ?? $duration,
        ];

        $isrcOnly = fn (): ?Recording => $isrc === null ? null : Recording::query()->whereNull('mbid')->where('isrc', $isrc)->first();

        if (! $registry instanceof RegistryRecording) {
            return $isrcOnly()
                ?? $this->adopt($nameOnly, [...$values, 'isrc' => $isrc])
                ?? Recording::query()->create([...$values, 'isrc' => $isrc]);
        }

        $byMbid = Recording::query()->where('mbid', $registry->mbid)->first();

        if ($byMbid instanceof Recording) {
            if ($byMbid->isrc === null && $isrc !== null) {
                $byMbid->update(['isrc' => $isrc]);
            }

            return $byMbid;
        }

        $adopted = $isrcOnly();

        if ($adopted instanceof Recording) {
            $adopted->update(['mbid' => $registry->mbid, ...$values]);

            return $adopted;
        }

        return $this->adopt($nameOnly, [...$values, 'mbid' => $registry->mbid, 'isrc' => $isrc])
            ?? Recording::query()->createOrFirst(['mbid' => $registry->mbid], [...$values, 'isrc' => $isrc]);
    }

    /**
     * Gives the track's name-only recording the identity just found, when
     * there is one.
     *
     * @param  array<string, int|string|null>  $values
     */
    private function adopt(?Recording $nameOnly, array $values): ?Recording
    {
        $nameOnly?->update($values);

        return $nameOnly;
    }
```

Also update the one existing caller of `recordingFor()` (in `record()`, already shown above) — there is no other.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest --tia --parallel tests/Unit/Actions/ResolveRecordingsTest.php`, then `tests/Unit/Jobs/EnrichmentJobsTest.php`.
Expected: PASS.

- [ ] **Step 6: Analyse, format, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze app/Actions/ResolveRecordings.php tests/Unit/Actions/ResolveRecordingsTest.php
git add app/Actions/ResolveRecordings.php tests/Unit/Actions/ResolveRecordingsTest.php
git commit -m "feat(enrichment): resolve through last.fm the tracks the registries miss"
```

---

### Task 5: Describing recordings with Last.fm

**Files:**
- Modify: `app/Actions/DescribeRecording.php`, `app/Jobs/EnrichRecording.php`, `app/Jobs/ResolveLibraryTracks.php`, `app/Actions/SummarizeLibraryEnrichment.php`
- Test: `tests/Unit/Actions/DescribeRecordingTest.php`, `tests/Unit/Jobs/EnrichmentJobsTest.php`, `tests/Unit/Actions/SummarizeLibraryEnrichmentTest.php`

**Interfaces:**
- Consumes: `LastFmGateway`, `Enrichment::isDue()`, `Enrichment::INFO`.
- Produces: `DescribeRecording->sources(): list<MetadataSource>` (Last.fm only when enabled), `DescribeRecording::endpoints(MetadataSource): list<string>` (`isrc` / `recording` / `info`, `top_tags`, `similar`), `DescribeRecording::LASTFM_ENDPOINTS`. `DescribeRecording::SOURCES` and `::endpoint()` are removed. `handle()` returns null when the source cannot identify the recording or nothing is due.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Actions/DescribeRecordingTest.php` (imports `App\Services\Metadata\LastFm\LastFmGateway`, `Tests\Support\FakeLastFmGateway`, `App\Exceptions\Metadata\MetadataSourceRateLimited`), append:

```php
it('asks Last.fm for info, tags and similar tracks by title and artist', function (): void {
    $lastFm = new FakeLastFmGateway();
    app()->instance(LastFmGateway::class, $lastFm);
    $recording = Recording::factory()->create(['mbid' => null, 'isrc' => null, 'title' => 'Loreley', 'artist_name' => 'Lord of the Lost']);
    $lastFm->trackInfos['Lord of the Lost|Loreley'] = metadataFixture('lastfm-track-info');

    $status = resolve(DescribeRecording::class)->handle($recording, MetadataSource::LastFm);

    expect($status)->toBe(EnrichmentStatus::Done)
        ->and($lastFm->calls)->toBe(['track_info:Lord of the Lost|Loreley', 'track_top_tags:Lord of the Lost|Loreley', 'track_similar:Lord of the Lost|Loreley'])
        ->and(Enrichment::query()->where('source', MetadataSource::LastFm)->pluck('status', 'endpoint')->all())
        ->toEqual(['info' => EnrichmentStatus::Done, 'top_tags' => EnrichmentStatus::NotFound, 'similar' => EnrichmentStatus::NotFound]);
});

it('asks Last.fm only for what is due', function (): void {
    $lastFm = new FakeLastFmGateway();
    app()->instance(LastFmGateway::class, $lastFm);
    $recording = Recording::factory()->create(['title' => 'Loreley', 'artist_name' => 'Lord of the Lost']);
    $describe = resolve(DescribeRecording::class);
    $describe->handle($recording, MetadataSource::LastFm);
    $lastFm->calls = [];

    expect($describe->handle($recording, MetadataSource::LastFm))->toBeNull()
        ->and($lastFm->calls)->toBe([]);

    $this->travel(8)->days();
    $describe->handle($recording, MetadataSource::LastFm);

    expect($lastFm->calls)->toBe(['track_info:Lord of the Lost|Loreley']);
});

it('resumes after a rate limit without asking again', function (): void {
    $lastFm = new FakeLastFmGateway();
    $lastFm->refuseAfterCalls = 1;
    app()->instance(LastFmGateway::class, $lastFm);
    $recording = Recording::factory()->create(['title' => 'Loreley', 'artist_name' => 'Lord of the Lost']);

    expect(fn (): ?EnrichmentStatus => resolve(DescribeRecording::class)->handle($recording, MetadataSource::LastFm))
        ->toThrow(MetadataSourceRateLimited::class);

    $lastFm->refuseAfterCalls = null;
    $lastFm->calls = [];
    resolve(DescribeRecording::class)->handle($recording, MetadataSource::LastFm);

    expect($lastFm->calls)->toBe(['track_top_tags:Lord of the Lost|Loreley', 'track_similar:Lord of the Lost|Loreley']);
});

it('leaves Last.fm out of the sources without a key', function (): void {
    expect(resolve(DescribeRecording::class)->sources())->toBe([MetadataSource::CreditsFm, MetadataSource::MusicBrainz])
        ->and(resolve(DescribeRecording::class)->handle(Recording::factory()->create(), MetadataSource::LastFm))->toBeNull();

    app()->instance(LastFmGateway::class, new FakeLastFmGateway());

    expect(resolve(DescribeRecording::class)->sources())->toBe([MetadataSource::CreditsFm, MetadataSource::MusicBrainz, MetadataSource::LastFm]);
});
```

In `tests/Unit/Jobs/EnrichmentJobsTest.php` (import `App\Services\Metadata\LastFm\LastFmGateway`, `Tests\Support\FakeLastFmGateway`), append:

```php
it('records a failure only on the endpoints left undone', function (): void {
    $lastFm = new FakeLastFmGateway();
    app()->instance(LastFmGateway::class, $lastFm);
    $recording = Recording::factory()->create(['title' => 'Loreley', 'artist_name' => 'Lord of the Lost']);
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, ['track' => []]);

    (new EnrichRecording($recording->id, MetadataSource::LastFm))->failed(new RuntimeException('gave up'));

    expect(Enrichment::query()->where('source', MetadataSource::LastFm)->pluck('status', 'endpoint')->all())
        ->toEqual(['info' => EnrichmentStatus::Done, 'top_tags' => EnrichmentStatus::Failed, 'similar' => EnrichmentStatus::Failed]);
});
```

In `tests/Unit/Actions/SummarizeLibraryEnrichmentTest.php` (imports `App\Services\Metadata\LastFm\LastFmGateway`, `Tests\Support\FakeLastFmGateway`), after "is done once every track is settled and described" (which keeps covering Last.fm off, the default fake), add:

```php
it('waits for Last.fm only when it has a key', function (): void {
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());
    $recording = resolvedTrack($this->playlist, 'video-aaaa1');
    describeWith($recording, MetadataSource::CreditsFm, MetadataSource::MusicBrainz);

    expect(resolve(SummarizeLibraryEnrichment::class)->handle($this->account)->state)->toBe(LibraryEnrichmentState::Running);

    describeWith($recording, MetadataSource::LastFm);

    expect(resolve(SummarizeLibraryEnrichment::class)->handle($this->account)->state)->toBe(LibraryEnrichmentState::Done);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest --tia --parallel tests/Unit/Actions/DescribeRecordingTest.php`, then `tests/Unit/Jobs/EnrichmentJobsTest.php`, then `tests/Unit/Actions/SummarizeLibraryEnrichmentTest.php`.
Expected: the new tests FAIL (`sources()` undefined; Last.fm returns null).

- [ ] **Step 3: Describe with Last.fm**

Replace `app/Actions/DescribeRecording.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\Data\TrackQuery;
use App\Services\Metadata\LastFm\LastFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;

final readonly class DescribeRecording
{
    /** @var list<string> */
    public const array LASTFM_ENDPOINTS = [Enrichment::INFO, 'top_tags', 'similar'];

    public function __construct(
        private CreditsFmGateway $creditsFm,
        private MusicBrainzGateway $musicBrainz,
        private LastFmGateway $lastFm,
    ) {}

    /**
     * The sources that describe recordings: Last.fm only with an API key.
     *
     * @return list<MetadataSource>
     */
    public function sources(): array
    {
        return $this->lastFm->enabled()
            ? [MetadataSource::CreditsFm, MetadataSource::MusicBrainz, MetadataSource::LastFm]
            : [MetadataSource::CreditsFm, MetadataSource::MusicBrainz];
    }

    /**
     * The endpoints a source is asked about a recording with.
     *
     * @return list<string>
     */
    public static function endpoints(MetadataSource $source): array
    {
        return match ($source) {
            MetadataSource::CreditsFm => ['isrc'],
            MetadataSource::LastFm => self::LASTFM_ENDPOINTS,
            default => ['recording'],
        };
    }

    /**
     * Fetches and stores what one source says about the recording. Null when
     * the source has no identifier to ask with, or nothing is due. Failures
     * propagate; Last.fm's answers stored before a failure are kept.
     */
    public function handle(Recording $recording, MetadataSource $source): ?EnrichmentStatus
    {
        if ($source === MetadataSource::LastFm) {
            return $this->lastFm->enabled() ? $this->askLastFm($recording) : null;
        }

        if ($source === MetadataSource::CreditsFm && $recording->isrc !== null) {
            $payload = $this->creditsFm->isrc($recording->isrc);
        } elseif ($source === MetadataSource::MusicBrainz && $recording->mbid !== null) {
            $payload = $this->musicBrainz->recording($recording->mbid);
        } else {
            return null;
        }

        $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
        Enrichment::store(Enrichment::RECORDING, $recording->id, $source, self::endpoints($source)[0], $status, $payload);

        return $status;
    }

    /**
     * Asks each Last.fm endpoint that is due, by title and artist: done when
     * one of them answered.
     */
    private function askLastFm(Recording $recording): ?EnrichmentStatus
    {
        $query = new TrackQuery($recording->title, $recording->artist_name);
        $calls = [
            Enrichment::INFO => fn (): ?array => $this->lastFm->trackInfo($query),
            'top_tags' => fn (): ?array => $this->lastFm->trackTopTags($query),
            'similar' => fn (): ?array => $this->lastFm->trackSimilar($query),
        ];
        $statuses = [];

        foreach ($calls as $endpoint => $call) {
            if (! Enrichment::isDue(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, $endpoint)) {
                continue;
            }

            $payload = $call();
            $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
            Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, $endpoint, $status, $payload);
            $statuses[] = $status;
        }

        if ($statuses === []) {
            return null;
        }

        return in_array(EnrichmentStatus::Done, $statuses, true) ? EnrichmentStatus::Done : EnrichmentStatus::NotFound;
    }
}
```

- [ ] **Step 4: Use the sources and endpoints everywhere**

`app/Jobs/EnrichRecording.php`, replace `failed()`:

```php
    /**
     * Marks as failed what this source still owes: answers stored before the
     * failure stay as they are.
     */
    public function failed(Throwable $exception): void
    {
        foreach (DescribeRecording::endpoints($this->source) as $endpoint) {
            if (Enrichment::isDue(Enrichment::RECORDING, $this->recordingId, $this->source, $endpoint)) {
                Enrichment::store(Enrichment::RECORDING, $this->recordingId, $this->source, $endpoint, EnrichmentStatus::Failed, error: $exception->getMessage());
            }
        }

        resolve(AnnounceEnrichmentProgress::class)->forRecording($this->recordingId);
    }
```

`app/Jobs/ResolveLibraryTracks.php`, in `describeResolved()`: replace `DescribeRecording::SOURCES` with `resolve(DescribeRecording::class)->sources()` (resolve it once before the loop: `$sources = resolve(DescribeRecording::class)->sources();`).

`app/Actions/SummarizeLibraryEnrichment.php`: add `public function __construct(private DescribeRecording $describe) {}` (keep any existing constructor parameters) and in `described()` replace `DescribeRecording::SOURCES` with `$this->describe->sources()`.

Run `grep -rn "DescribeRecording::SOURCES\|DescribeRecording::endpoint(" app tests` and replace any remaining use the same way.

- [ ] **Step 5: Run the tests to verify they pass**

Run each: `tests/Unit/Actions/DescribeRecordingTest.php`, `tests/Unit/Jobs/EnrichmentJobsTest.php`, `tests/Unit/Actions/SummarizeLibraryEnrichmentTest.php`, `tests/Unit/Actions/AnnounceEnrichmentProgressTest.php`.
Expected: PASS.

- [ ] **Step 6: Analyse, format, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze app/Actions app/Jobs tests/Unit/Actions tests/Unit/Jobs
git add app/Actions app/Jobs tests/Unit/Actions tests/Unit/Jobs
git commit -m "feat(enrichment): describe recordings with last.fm when its answers are due"
```

---

### Task 6: Projecting Last.fm onto recordings

**Files:**
- Modify: `app/Actions/ProjectEnrichment.php`, `app/Jobs/ProjectRecordingMetadata.php`, `app/Actions/DescribeContributor.php` (only `undescribedSources()` and its constructor here; Task 7 adds the rest)
- Test: `tests/Unit/Actions/ProjectEnrichmentTest.php`, `tests/Unit/Actions/DescribeContributorTest.php`

**Interfaces:**
- Consumes: `LastFmMapper::track()`, `::tags()`, `::similarTracks()`, `::popularity()`, `Enrichment::fetchedAt()`, `PopularitySample::record()`, `SimilarRecording`.
- Produces: `ProjectEnrichment::handle(Recording): list<Contributor>` now returns **every** main artist (no longer filtered); `DescribeContributor->undescribedSources(Contributor): list<MetadataSource>` (MusicBrainz when it has an MBID, Last.fm when enabled; only sources with no `enrichments` row yet for that contributor).

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Actions/ProjectEnrichmentTest.php` (imports `App\Models\PopularitySample`, `App\Models\SimilarRecording`), add a helper and tests:

```php
function withLastFm(Recording $recording): Recording
{
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, metadataFixture('lastfm-track-info'));
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, 'top_tags', EnrichmentStatus::Done, metadataFixture('lastfm-track-top-tags'));
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, 'similar', EnrichmentStatus::Done, metadataFixture('lastfm-track-similar'));

    return $recording;
}

it('projects Last.fm tags beside MusicBrainz ones', function (): void {
    $recording = withLastFm(describedSurvival());

    resolve(ProjectEnrichment::class)->handle($recording);

    expect(DB::table('recording_tags')->where('recording_id', $recording->id)->where('source', 'lastfm')->count())->toBe(7)
        ->and(DB::table('recording_tags')->where('recording_id', $recording->id)->where('source', 'musicbrainz')->exists())->toBeTrue()
        ->and(recordingTagWeight($recording, 'indie'))->toBe(25);
});

it('projects similar tracks and popularity, one sample per reading', function (): void {
    $recording = withLastFm(Recording::factory()->create());

    resolve(ProjectEnrichment::class)->handle($recording);
    resolve(ProjectEnrichment::class)->handle($recording);

    expect(SimilarRecording::query()->where('recording_id', $recording->id)->count())->toBe(5)
        ->and(SimilarRecording::query()->orderByDesc('match')->first()?->title)->toBe('Six Feet Underground')
        ->and($recording->fresh()?->lastfm_listeners)->toBe(833437)
        ->and(PopularitySample::query()->count())->toBe(1);

    $this->travel(8)->days();
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, metadataFixture('lastfm-track-info'));
    resolve(ProjectEnrichment::class)->handle($recording);

    expect(PopularitySample::query()->count())->toBe(2);
});

it('credits the Last.fm artist only when MusicBrainz credits none', function (): void {
    $nameOnly = withLastFm(Recording::factory()->create(['mbid' => null, 'isrc' => null]));
    $registered = withLastFm(describedSurvival());

    $nameOnlyArtists = resolve(ProjectEnrichment::class)->handle($nameOnly);
    resolve(ProjectEnrichment::class)->handle($registered);

    expect(array_map(fn (Contributor $contributor): string => $contributor->name, $nameOnlyArtists))->toBe(['Ricky Montgomery'])
        ->and(RecordingContributor::query()->where('recording_id', $nameOnly->id)->where('source', 'lastfm')->count())->toBe(1)
        ->and(RecordingContributor::query()->where('recording_id', $registered->id)->where('source', 'lastfm')->exists())->toBeFalse();
});
```

Change the existing test "names the main artists still to describe" to:

```php
it('names the main artists', function (): void {
    $artists = resolve(ProjectEnrichment::class)->handle(describedSurvival());

    expect(array_map(fn (Contributor $contributor): ?string => $contributor->mbid, $artists))->toContain('9c9f1380-2516-4fc9-a3e6-f9f61941d090');
});
```

In `tests/Unit/Actions/DescribeContributorTest.php` (imports `App\Services\Metadata\LastFm\LastFmGateway`, `Tests\Support\FakeLastFmGateway`, `App\Models\Enrichment`, `App\Enums\EnrichmentStatus`), append:

```php
it('names the sources that never described a contributor', function (): void {
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());
    $withMbid = Contributor::factory()->create();
    $nameOnly = Contributor::factory()->create(['mbid' => null]);
    Enrichment::store(Enrichment::CONTRIBUTOR, $withMbid->id, MetadataSource::MusicBrainz, 'artist', EnrichmentStatus::Done, ['id' => 'x']);

    expect(resolve(DescribeContributor::class)->undescribedSources($withMbid))->toBe([MetadataSource::LastFm])
        ->and(resolve(DescribeContributor::class)->undescribedSources($nameOnly))->toBe([MetadataSource::LastFm]);

    app()->instance(LastFmGateway::class, new FakeLastFmGateway(enabled: false));

    expect(resolve(DescribeContributor::class)->undescribedSources($nameOnly))->toBe([]);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest --tia --parallel tests/Unit/Actions/ProjectEnrichmentTest.php`, then `tests/Unit/Actions/DescribeContributorTest.php`.
Expected: the new tests FAIL.

- [ ] **Step 3: Project Last.fm in `ProjectEnrichment`**

Replace `handle()` in `app/Actions/ProjectEnrichment.php` (keep `contributorFor()`; add imports `App\Models\PopularitySample`, `App\Models\SimilarRecording`, `App\Services\Metadata\Data\LastFmTrack`, `App\Services\Metadata\Data\WeightedTag`, `App\Services\Metadata\LastFm\LastFmMapper`):

```php
    /**
     * Rebuilds the recording's credits, tags, similar tracks and popularity
     * from every stored payload, and returns its main artists. Last.fm names
     * the main artist only when MusicBrainz credits none.
     *
     * @return list<Contributor>
     */
    public function handle(Recording $recording): array
    {
        $creditsFm = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::CreditsFm, 'isrc');
        $musicBrainz = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::MusicBrainz, 'recording');
        $lastFmInfo = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO);
        $lastFmTags = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, 'top_tags');
        $lastFmSimilar = Enrichment::payloadFor(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, 'similar');

        $credits = [];

        foreach ($creditsFm === null ? [] : CreditsFmMapper::credits($creditsFm) as $credit) {
            $credits[] = ['credit' => $credit, 'source' => MetadataSource::CreditsFm];
        }

        $registry = $musicBrainz === null ? null : MusicBrainzMapper::recording($musicBrainz);

        foreach ($registry === null ? [] : $registry->artists as $artist) {
            $credits[] = ['credit' => new Credit($artist['name'], CreditType::Artist, '', $artist['mbid'], null), 'source' => MetadataSource::MusicBrainz];
        }

        $lastFmTrack = $lastFmInfo === null ? null : LastFmMapper::track($lastFmInfo);

        if (($registry === null || $registry->artists === []) && $lastFmTrack instanceof LastFmTrack) {
            $credits[] = ['credit' => new Credit($lastFmTrack->artist, CreditType::Artist, '', null, null), 'source' => MetadataSource::LastFm];
        }

        $tags = [
            ...array_map(fn (WeightedTag $tag): array => ['tag' => $tag, 'source' => MetadataSource::MusicBrainz], $musicBrainz === null ? [] : MusicBrainzMapper::tags($musicBrainz)),
            ...array_map(fn (WeightedTag $tag): array => ['tag' => $tag, 'source' => MetadataSource::LastFm], $lastFmTags === null ? [] : LastFmMapper::tags($lastFmTags)),
        ];
        $similar = $lastFmSimilar === null ? [] : LastFmMapper::similarTracks($lastFmSimilar);
        $popularity = $lastFmInfo === null ? null : LastFmMapper::popularity($lastFmInfo);
        $measuredAt = Enrichment::fetchedAt(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO);
        $detail = $creditsFm === null ? null : CreditsFmMapper::detail($creditsFm);

        return DB::transaction(function () use ($recording, $credits, $tags, $similar, $popularity, $measuredAt, $detail, $registry): array {
            $recording->update([
                ...array_filter([
                    'iswc' => $recording->iswc ?? $detail?->iswc,
                    'release_date' => $recording->release_date ?? $detail->releaseDate ?? $registry?->firstReleaseDate,
                    'lastfm_listeners' => $popularity?->listeners,
                    'lastfm_playcount' => $popularity?->playcount,
                ], fn (mixed $value): bool => $value !== null),
                'projected_at' => now(),
            ]);

            RecordingContributor::query()->where('recording_id', $recording->id)->delete();
            DB::table('recording_tags')->where('recording_id', $recording->id)->delete();
            SimilarRecording::query()->where('recording_id', $recording->id)->where('source', MetadataSource::LastFm)->delete();

            $mainArtists = [];

            foreach ($credits as ['credit' => $credit, 'source' => $source]) {
                $contributor = $this->contributorFor($credit);

                RecordingContributor::query()->createOrFirst([
                    'recording_id' => $recording->id,
                    'contributor_id' => $contributor->id,
                    'credit_type' => $credit->type,
                    'role' => $credit->role,
                    'source' => $source,
                ], ['credit_attributes' => $credit->attributes]);

                if ($credit->type === CreditType::Artist) {
                    $mainArtists[$contributor->id] = $contributor;
                }
            }

            foreach ($tags as ['tag' => $tag, 'source' => $source]) {
                DB::table('recording_tags')->insertOrIgnore([
                    'recording_id' => $recording->id,
                    'tag_id' => Tag::named($tag->name, $tag->isGenre)->id,
                    'source' => $source->value,
                    'weight' => $tag->weight,
                ]);
            }

            foreach ($similar as $track) {
                SimilarRecording::query()->create([
                    'recording_id' => $recording->id,
                    'title' => $track->title,
                    'artist_name' => $track->artist,
                    'match' => $track->match,
                    'source' => MetadataSource::LastFm,
                ]);
            }

            if ($popularity !== null && $measuredAt !== null) {
                PopularitySample::record(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, $popularity->listeners, $popularity->playcount, $measuredAt);
            }

            return array_values($mainArtists);
        });
    }
```

If the existing `handle()` already uses a different variable layout for `$detail` / `$registry`, keep its identity update rules exactly as they were; only the Last.fm parts above are new.

- [ ] **Step 4: Name the sources still owed to each artist**

In `app/Actions/DescribeContributor.php`, change the constructor to `public function __construct(private MusicBrainzGateway $musicBrainz, private LastFmGateway $lastFm) {}` (import `App\Services\Metadata\LastFm\LastFmGateway`) and add:

```php
    /**
     * The sources able to describe this contributor that never did:
     * MusicBrainz needs an MBID, Last.fm a key.
     *
     * @return list<MetadataSource>
     */
    public function undescribedSources(Contributor $contributor): array
    {
        $able = [];

        if ($contributor->mbid !== null) {
            $able[] = MetadataSource::MusicBrainz;
        }

        if ($this->lastFm->enabled()) {
            $able[] = MetadataSource::LastFm;
        }

        return array_values(array_filter($able, fn (MetadataSource $source): bool => Enrichment::query()
            ->where('subject_type', Enrichment::CONTRIBUTOR)
            ->where('subject_key', $contributor->id)
            ->where('source', $source)
            ->doesntExist()));
    }
```

`app/Jobs/ProjectRecordingMetadata.php`, in `handle()`:

```php
        $describe = resolve(DescribeContributor::class);

        foreach (resolve(ProjectEnrichment::class)->handle($recording) as $contributor) {
            foreach ($describe->undescribedSources($contributor) as $source) {
                EnrichContributor::dispatch($contributor->id, $source);
            }
        }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run each: `tests/Unit/Actions/ProjectEnrichmentTest.php`, `tests/Unit/Actions/DescribeContributorTest.php`, `tests/Unit/Jobs/EnrichmentJobsTest.php`.
Expected: PASS.

- [ ] **Step 6: Analyse, format, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze app/Actions app/Jobs tests/Unit/Actions
git add app/Actions app/Jobs tests/Unit/Actions
git commit -m "feat(enrichment): project last.fm tags, similar tracks and popularity"
```

---

### Task 7: Describing and projecting artists with Last.fm

**Files:**
- Modify: `app/Actions/DescribeContributor.php`, `app/Jobs/EnrichContributor.php`
- Rename: `app/Actions/ProjectContributorTags.php` → `app/Actions/ProjectContributorMetadata.php`, `tests/Unit/Actions/ProjectContributorTagsTest.php` → `tests/Unit/Actions/ProjectContributorMetadataTest.php` (`git mv`)
- Test: `tests/Unit/Actions/DescribeContributorTest.php`, `tests/Unit/Actions/ProjectContributorMetadataTest.php`

**Interfaces:**
- Consumes: `LastFmGateway::artistInfo()`, `::artistTopTags()`, `::artistSimilar()`, `LastFmMapper`, `PopularitySample::record()`, `SimilarContributor`, `Enrichment::isDue()`.
- Produces: `DescribeContributor::endpoints(MetadataSource): list<string>` (`artist` / `info`, `top_tags`, `similar`); `ProjectContributorMetadata::handle(Contributor): void`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Actions/DescribeContributorTest.php`, append:

```php
it('asks Last.fm about an artist by name, with or without an MBID', function (): void {
    $lastFm = new FakeLastFmGateway();
    app()->instance(LastFmGateway::class, $lastFm);
    $contributor = Contributor::factory()->create(['name' => 'Lord of the Lost', 'mbid' => null]);
    $lastFm->artistInfos['Lord of the Lost'] = metadataFixture('lastfm-artist-info');

    $status = resolve(DescribeContributor::class)->handle($contributor, MetadataSource::LastFm);

    expect($status)->toBe(EnrichmentStatus::Done)
        ->and($lastFm->calls)->toBe(['artist_info:Lord of the Lost', 'artist_top_tags:Lord of the Lost', 'artist_similar:Lord of the Lost']);
});
```

`git mv tests/Unit/Actions/ProjectContributorTagsTest.php tests/Unit/Actions/ProjectContributorMetadataTest.php`, replace `ProjectContributorTags` by `ProjectContributorMetadata` in it, and append (imports `App\Models\PopularitySample`, `App\Models\SimilarContributor`, `App\Models\Enrichment`, `App\Enums\EnrichmentStatus`, `App\Enums\MetadataSource`, `Illuminate\Support\Facades\DB`):

```php
it('projects Last.fm artist tags, similar artists and popularity', function (): void {
    $contributor = Contributor::factory()->create(['name' => 'Lord of the Lost']);
    Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, metadataFixture('lastfm-artist-info'));
    Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, 'top_tags', EnrichmentStatus::Done, metadataFixture('lastfm-artist-top-tags'));
    Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, 'similar', EnrichmentStatus::Done, metadataFixture('lastfm-artist-similar'));

    resolve(ProjectContributorMetadata::class)->handle($contributor);
    resolve(ProjectContributorMetadata::class)->handle($contributor);

    expect(DB::table('contributor_tags')->where('contributor_id', $contributor->id)->where('source', 'lastfm')->count())->toBe(6)
        ->and(SimilarContributor::query()->where('contributor_id', $contributor->id)->count())->toBe(5)
        ->and($contributor->fresh()?->lastfm_listeners)->toBe(171227)
        ->and(PopularitySample::query()->where('subject_type', Enrichment::CONTRIBUTOR)->count())->toBe(1);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run each: `tests/Unit/Actions/DescribeContributorTest.php`, `tests/Unit/Actions/ProjectContributorMetadataTest.php`.
Expected: FAIL.

- [ ] **Step 3: Describe artists with Last.fm**

In `app/Actions/DescribeContributor.php` (import `App\Models\Contributor` if missing):

```php
    /**
     * The endpoints a source is asked about a contributor with.
     *
     * @return list<string>
     */
    public static function endpoints(MetadataSource $source): array
    {
        return $source === MetadataSource::LastFm ? DescribeRecording::LASTFM_ENDPOINTS : ['artist'];
    }

    /**
     * Fetches and stores what one source says about the contributor. Null
     * when the source has no identifier to ask with, or nothing is due.
     */
    public function handle(Contributor $contributor, MetadataSource $source): ?EnrichmentStatus
    {
        if ($source === MetadataSource::LastFm) {
            return $this->lastFm->enabled() ? $this->askLastFm($contributor) : null;
        }

        if ($source !== MetadataSource::MusicBrainz || $contributor->mbid === null) {
            return null;
        }

        $payload = $this->musicBrainz->artist($contributor->mbid);
        $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
        Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, $source, 'artist', $status, $payload);

        return $status;
    }

    /**
     * Asks each Last.fm endpoint that is due, by name: done when one of them
     * answered.
     */
    private function askLastFm(Contributor $contributor): ?EnrichmentStatus
    {
        $calls = [
            Enrichment::INFO => fn (): ?array => $this->lastFm->artistInfo($contributor->name),
            'top_tags' => fn (): ?array => $this->lastFm->artistTopTags($contributor->name),
            'similar' => fn (): ?array => $this->lastFm->artistSimilar($contributor->name),
        ];
        $statuses = [];

        foreach ($calls as $endpoint => $call) {
            if (! Enrichment::isDue(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, $endpoint)) {
                continue;
            }

            $payload = $call();
            $status = $payload === null ? EnrichmentStatus::NotFound : EnrichmentStatus::Done;
            Enrichment::store(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, $endpoint, $status, $payload);
            $statuses[] = $status;
        }

        if ($statuses === []) {
            return null;
        }

        return in_array(EnrichmentStatus::Done, $statuses, true) ? EnrichmentStatus::Done : EnrichmentStatus::NotFound;
    }
```

- [ ] **Step 4: Project artists**

`git mv app/Actions/ProjectContributorTags.php app/Actions/ProjectContributorMetadata.php`, then:

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\PopularitySample;
use App\Models\SimilarContributor;
use App\Models\Tag;
use App\Services\Metadata\Data\WeightedTag;
use App\Services\Metadata\LastFm\LastFmMapper;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;
use Illuminate\Support\Facades\DB;

final readonly class ProjectContributorMetadata
{
    /**
     * Rebuilds the contributor's tags (MusicBrainz and Last.fm), similar
     * artists and popularity from its stored payloads.
     */
    public function handle(Contributor $contributor): void
    {
        $musicBrainz = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::MusicBrainz, 'artist');
        $lastFmInfo = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, Enrichment::INFO);
        $lastFmTags = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, 'top_tags');
        $lastFmSimilar = Enrichment::payloadFor(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, 'similar');

        $tags = [
            ...array_map(fn (WeightedTag $tag): array => ['tag' => $tag, 'source' => MetadataSource::MusicBrainz], $musicBrainz === null ? [] : MusicBrainzMapper::tags($musicBrainz)),
            ...array_map(fn (WeightedTag $tag): array => ['tag' => $tag, 'source' => MetadataSource::LastFm], $lastFmTags === null ? [] : LastFmMapper::tags($lastFmTags)),
        ];
        $similar = $lastFmSimilar === null ? [] : LastFmMapper::similarArtists($lastFmSimilar);
        $popularity = $lastFmInfo === null ? null : LastFmMapper::popularity($lastFmInfo);
        $measuredAt = Enrichment::fetchedAt(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, Enrichment::INFO);

        DB::transaction(function () use ($contributor, $tags, $similar, $popularity, $measuredAt): void {
            DB::table('contributor_tags')->where('contributor_id', $contributor->id)->delete();
            SimilarContributor::query()->where('contributor_id', $contributor->id)->where('source', MetadataSource::LastFm)->delete();

            foreach ($tags as ['tag' => $tag, 'source' => $source]) {
                DB::table('contributor_tags')->insertOrIgnore([
                    'contributor_id' => $contributor->id,
                    'tag_id' => Tag::named($tag->name, $tag->isGenre)->id,
                    'source' => $source->value,
                    'weight' => $tag->weight,
                ]);
            }

            foreach ($similar as $artist) {
                SimilarContributor::query()->create([
                    'contributor_id' => $contributor->id,
                    'name' => $artist->name,
                    'match' => $artist->match,
                    'source' => MetadataSource::LastFm,
                ]);
            }

            if ($popularity !== null) {
                $contributor->update(['lastfm_listeners' => $popularity->listeners, 'lastfm_playcount' => $popularity->playcount]);
            }

            if ($popularity !== null && $measuredAt !== null) {
                PopularitySample::record(Enrichment::CONTRIBUTOR, $contributor->id, MetadataSource::LastFm, $popularity->listeners, $popularity->playcount, $measuredAt);
            }
        });
    }
}
```

`app/Jobs/EnrichContributor.php`: use `ProjectContributorMetadata` instead of `ProjectContributorTags`, and replace `failed()`:

```php
    /**
     * Marks as failed what this source still owes about the contributor.
     */
    public function failed(Throwable $exception): void
    {
        foreach (DescribeContributor::endpoints($this->source) as $endpoint) {
            if (Enrichment::isDue(Enrichment::CONTRIBUTOR, $this->contributorId, $this->source, $endpoint)) {
                Enrichment::store(Enrichment::CONTRIBUTOR, $this->contributorId, $this->source, $endpoint, EnrichmentStatus::Failed, error: $exception->getMessage());
            }
        }
    }
```

Run `grep -rn "ProjectContributorTags" app tests` and replace what remains.

- [ ] **Step 5: Run the tests to verify they pass**

Run each: `tests/Unit/Actions/DescribeContributorTest.php`, `tests/Unit/Actions/ProjectContributorMetadataTest.php`, `tests/Unit/Jobs/EnrichmentJobsTest.php`.
Expected: PASS.

- [ ] **Step 6: Analyse, format, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze app/Actions app/Jobs tests/Unit/Actions
git add -A app/Actions app/Jobs tests/Unit/Actions
git commit -m "feat(enrichment): describe main artists with last.fm tags, similar and popularity"
```

---

### Task 8: Chart snapshots

**Files:**
- Create: `app/Actions/SnapshotChart.php`, `app/Jobs/TakeChartSnapshot.php`, `app/Console/Commands/TakeChartSnapshotsCommand.php`
- Modify: `app/Services/Metadata/EnrichmentTelemetry.php`, `app/Services/Metadata/OpenTelemetryEnrichmentTelemetry.php`, `tests/Support/FakeEnrichmentTelemetry.php`, `routes/console.php`
- Test: `tests/Unit/Actions/SnapshotChartTest.php`, `tests/Feature/Commands/MetadataCommandsTest.php`

**Interfaces:**
- Consumes: `LastFmGateway::topTracks()`, `LastFmMapper::chart()`, `MusicText::matchTitle()`, `::matchArtist()`, `ChartSnapshot`, `ChartEntry`.
- Produces: `SnapshotChart::CHARTS` (`array<string, string|null>`), `SnapshotChart->handle(string $chart): ?ChartSnapshot` (null when already taken today); job `TakeChartSnapshot(string $chart)`; command `metadata:charts`; `EnrichmentTelemetry::chartEntries(string $chart, int $linked, int $unlinked): void`; fake `chartEntries` array `array<string, array{linked: int, unlinked: int}>`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Actions/SnapshotChartTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\SnapshotChart;
use App\Models\ChartEntry;
use App\Models\ChartSnapshot;
use App\Models\Recording;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\LastFm\LastFmGateway;
use Tests\Support\FakeEnrichmentTelemetry;
use Tests\Support\FakeLastFmGateway;

beforeEach(function (): void {
    $this->lastFm = new FakeLastFmGateway();
    $this->telemetry = new FakeEnrichmentTelemetry();
    app()->instance(LastFmGateway::class, $this->lastFm);
    app()->instance(EnrichmentTelemetry::class, $this->telemetry);
    $this->lastFm->topTracks[''] = metadataFixture('lastfm-chart-top-tracks');
    $this->lastFm->topTracks['belgium'] = metadataFixture('lastfm-geo-top-tracks');
});

it('takes a chart and links the tracks the library knows', function (): void {
    $known = Recording::factory()->create(['title' => 'Nicole Kidman', 'artist_name' => 'Adéla']);

    $snapshot = resolve(SnapshotChart::class)->handle('global');

    expect($snapshot?->entries()->count())->toBe(5)
        ->and(ChartEntry::query()->where('rank', 1)->sole()->recording_id)->toBe($known->id)
        ->and(ChartEntry::query()->where('rank', 2)->sole()->recording_id)->toBeNull()
        ->and($this->telemetry->chartEntries['global'])->toBe(['linked' => 1, 'unlinked' => 4]);
});

it('asks a country chart by its English name', function (): void {
    resolve(SnapshotChart::class)->handle('country:BE');

    expect($this->lastFm->calls)->toBe(['top_tracks:belgium'])
        ->and(ChartEntry::query()->where('rank', 1)->sole()->title)->toBe("Ain't In LA");
});

it('takes each chart once a day', function (): void {
    resolve(SnapshotChart::class)->handle('global');

    expect(resolve(SnapshotChart::class)->handle('global'))->toBeNull()
        ->and($this->lastFm->calls)->toBe(['top_tracks:']);

    $this->travel(1)->days();
    resolve(SnapshotChart::class)->handle('global');

    expect(ChartSnapshot::query()->count())->toBe(2);
});

it('refuses a chart it does not follow', function (): void {
    expect(fn (): ?ChartSnapshot => resolve(SnapshotChart::class)->handle('country:XX'))->toThrow(InvalidArgumentException::class);
});
```

`tests/Feature/Commands/MetadataCommandsTest.php`, append (imports `App\Jobs\TakeChartSnapshot`, `App\Services\Metadata\LastFm\LastFmGateway`, `Tests\Support\FakeLastFmGateway`):

```php
it('queues one snapshot per chart followed', function (): void {
    Queue::fake();
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());

    expect(Artisan::call('metadata:charts'))->toBe(0);

    Queue::assertPushed(TakeChartSnapshot::class, 4);
    Queue::assertPushed(TakeChartSnapshot::class, fn (TakeChartSnapshot $job): bool => $job->chart === 'country:US');
});

it('takes no chart without a key', function (): void {
    Queue::fake();

    expect(Artisan::call('metadata:charts'))->toBe(0);

    Queue::assertNotPushed(TakeChartSnapshot::class);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run each: `tests/Unit/Actions/SnapshotChartTest.php`, `tests/Feature/Commands/MetadataCommandsTest.php`.
Expected: FAIL.

- [ ] **Step 3: Add the telemetry instrument**

`EnrichmentTelemetry` (interface), add:

```php
    /**
     * One chart snapshot: how many entries are tracks the library knows.
     */
    public function chartEntries(string $chart, int $linked, int $unlinked): void;
```

`OpenTelemetryEnrichmentTelemetry`, add:

```php
    public function chartEntries(string $chart, int $linked, int $unlinked): void
    {
        $counter = Meter::counter('sonder.enrichment.chart_entries', '{entry}', 'Chart entries snapshotted, by chart and whether the library knows them');
        $counter->add($linked, ['chart' => $chart, 'linked' => 'true']);
        $counter->add($unlinked, ['chart' => $chart, 'linked' => 'false']);
    }
```

`tests/Support/FakeEnrichmentTelemetry.php`, add:

```php
    /** @var array<string, array{linked: int, unlinked: int}> */
    public array $chartEntries = [];

    public function chartEntries(string $chart, int $linked, int $unlinked): void
    {
        $this->chartEntries[$chart] = ['linked' => $linked, 'unlinked' => $unlinked];
    }
```

- [ ] **Step 4: Write the action**

`app/Actions/SnapshotChart.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MetadataSource;
use App\Models\ChartSnapshot;
use App\Models\Recording;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\LastFm\LastFmGateway;
use App\Services\Metadata\LastFm\LastFmMapper;
use App\Support\MusicText;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SnapshotChart
{
    /**
     * The charts followed, each with the Last.fm country it is asked for
     * (null worldwide).
     *
     * @var array<string, string|null>
     */
    public const array CHARTS = [
        'global' => null,
        'country:BE' => 'belgium',
        'country:FR' => 'france',
        'country:US' => 'united states',
    ];

    public function __construct(
        private LastFmGateway $lastFm,
        private EnrichmentTelemetry $telemetry,
    ) {}

    /**
     * Takes today's snapshot of one chart and links its entries to the
     * recordings the library knows. Null when today's is already taken.
     */
    public function handle(string $chart): ?ChartSnapshot
    {
        if (! array_key_exists($chart, self::CHARTS)) {
            throw new InvalidArgumentException("Sonder does not follow the [{$chart}] chart.");
        }

        $today = now()->toDateString();
        $taken = ChartSnapshot::query()
            ->where('source', MetadataSource::LastFm)
            ->where('chart', $chart)
            ->whereDate('taken_on', $today)
            ->exists();

        if ($taken) {
            return null;
        }

        $positions = LastFmMapper::chart($this->lastFm->topTracks(self::CHARTS[$chart]));

        return DB::transaction(function () use ($chart, $today, $positions): ChartSnapshot {
            $snapshot = ChartSnapshot::query()->create(['source' => MetadataSource::LastFm, 'chart' => $chart, 'taken_on' => $today]);
            $linked = 0;

            foreach ($positions as $position) {
                $recordingId = Recording::query()
                    ->where('match_title', MusicText::matchTitle($position->title))
                    ->where('match_artist', MusicText::matchArtist($position->artist))
                    ->value('id');

                $snapshot->entries()->create([
                    'rank' => $position->rank,
                    'title' => $position->title,
                    'artist_name' => $position->artist,
                    'listeners' => $position->listeners,
                    'playcount' => $position->playcount,
                    'recording_id' => is_string($recordingId) ? $recordingId : null,
                ]);

                $linked += is_string($recordingId) ? 1 : 0;
            }

            $this->telemetry->chartEntries($chart, $linked, count($positions) - $linked);

            return $snapshot;
        });
    }
}
```

- [ ] **Step 5: Write the job and the command, and schedule it**

`php artisan make:job TakeChartSnapshot --no-interaction`, then:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\SnapshotChart;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Takes today's snapshot of one chart.
 */
final class TakeChartSnapshot implements ShouldQueue
{
    use Queueable;

    /**
     * Releases for a rate limit are not exceptions: they may repeat until
     * `retryUntil()`. Real failures get three tries.
     */
    public int $maxExceptions = 3;

    public function __construct(public readonly string $chart)
    {
        $this->onQueue('enrichment');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->plus(hours: 12);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(SnapshotChart $snapshot): void
    {
        try {
            $snapshot->handle($this->chart);
        } catch (MetadataSourceRateLimited $exception) {
            $this->release($exception->retryAfter);
        }
    }
}
```

`php artisan make:command TakeChartSnapshotsCommand --no-interaction`, then:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SnapshotChart;
use App\Jobs\TakeChartSnapshot;
use App\Services\Metadata\LastFm\LastFmGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metadata:charts')]
#[Description('Queue today\'s snapshot of every chart followed (Last.fm)')]
final class TakeChartSnapshotsCommand extends Command
{
    public function handle(LastFmGateway $lastFm): int
    {
        if (! $lastFm->enabled()) {
            $this->components->warn('No Last.fm API key: no chart taken.');

            return self::SUCCESS;
        }

        foreach (array_keys(SnapshotChart::CHARTS) as $chart) {
            TakeChartSnapshot::dispatch($chart);
        }

        $this->components->info('Queued '.count(SnapshotChart::CHARTS).' chart snapshots.');

        return self::SUCCESS;
    }
}
```

`routes/console.php`, append:

```php

/*
 * Charts move daily; one snapshot a day also leaves a scale-to-zero
 * environment asleep the rest of the time.
 */
Schedule::command('metadata:charts')->daily();
```

- [ ] **Step 6: Run the tests to verify they pass**

Run each: `tests/Unit/Actions/SnapshotChartTest.php`, `tests/Feature/Commands/MetadataCommandsTest.php`.
Expected: PASS.

- [ ] **Step 7: Analyse, format, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze app/Actions/SnapshotChart.php app/Jobs/TakeChartSnapshot.php app/Console/Commands app/Services/Metadata routes/console.php tests/Support tests/Unit/Actions/SnapshotChartTest.php tests/Feature/Commands
git add app/Actions/SnapshotChart.php app/Jobs/TakeChartSnapshot.php app/Console/Commands app/Services/Metadata routes/console.php tests/Support tests/Unit/Actions/SnapshotChartTest.php tests/Feature/Commands
git commit -m "feat(enrichment): snapshot four last.fm charts every day"
```

---

### Task 9: Rollout and coverage

**Files:**
- Modify: `app/Console/Commands/RetryDueMetadataCommand.php`, `app/Console/Commands/EnrichMetadataCommand.php`, `app/Actions/QueueTrackResolution.php`, `app/Actions/RecordEnrichmentCoverage.php`
- Test: `tests/Feature/Commands/MetadataCommandsTest.php`, `tests/Unit/Actions/QueueTrackResolutionTest.php`, `tests/Unit/Actions/RecordEnrichmentCoverageTest.php`

**Interfaces:**
- Consumes: `DescribeRecording->sources()`, `DescribeContributor->undescribedSources()`, `EnrichRecording`, `EnrichContributor`.
- Produces: `metadata:retry-due` describes never-described recordings and main artists, one job per subject and source; `metadata:enrich {--refresh} {--unresolved}`; `QueueTrackResolution::handle(?YouTubeMusicAccount $account = null, bool $refresh = false, bool $unresolved = false): int`; coverage facets `lastfm_tags`, `similar`, `popularity`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Commands/MetadataCommandsTest.php`, append (imports `App\Jobs\EnrichContributor`, `App\Models\Contributor`, `App\Models\RecordingContributor`, `App\Enums\CreditType`, `App\Enums\MetadataSource`):

```php
it('describes what a source never described', function (): void {
    Queue::fake();
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());
    $nameOnly = Recording::factory()->create(['mbid' => null, 'isrc' => null]);
    $artist = Contributor::factory()->create(['mbid' => null]);
    RecordingContributor::query()->create(['recording_id' => $nameOnly->id, 'contributor_id' => $artist->id, 'credit_type' => CreditType::Artist, 'role' => '', 'source' => MetadataSource::LastFm, 'credit_attributes' => []]);

    Artisan::call('metadata:retry-due');

    Queue::assertPushed(EnrichRecording::class, 1);
    Queue::assertPushed(EnrichRecording::class, fn (EnrichRecording $job): bool => $job->recordingId === $nameOnly->id && $job->source === MetadataSource::LastFm);
    Queue::assertPushed(EnrichContributor::class, fn (EnrichContributor $job): bool => $job->contributorId === $artist->id && $job->source === MetadataSource::LastFm);
});

it('queues one Last.fm description per recording when several endpoints are due', function (): void {
    Queue::fake();
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());
    $recording = Recording::factory()->create();
    foreach (['info', 'top_tags', 'similar'] as $endpoint) {
        Enrichment::factory()->create(['subject_key' => $recording->id, 'source' => MetadataSource::LastFm, 'endpoint' => $endpoint, 'next_attempt_at' => now()->subMinute()]);
    }
    foreach ([MetadataSource::CreditsFm, MetadataSource::MusicBrainz] as $source) {
        Enrichment::factory()->create(['subject_key' => $recording->id, 'source' => $source, 'endpoint' => 'x', 'next_attempt_at' => now()->addDay()]);
    }

    Artisan::call('metadata:retry-due');

    Queue::assertPushed(EnrichRecording::class, 1);
});

it('queues the unresolved tracks on demand', function (): void {
    Queue::fake();
    libraryTrack(null, 'video000001');
    libraryTrack(null, 'video000002');
    libraryTrack(null, 'video000003');
    RecordingResolution::factory()->create(['external_id' => 'video000001', 'status' => ResolutionStatus::NotFound, 'recording_id' => null]);
    RecordingResolution::factory()->create(['external_id' => 'video000002', 'status' => ResolutionStatus::Resolved]);

    expect(Artisan::call('metadata:enrich', ['--unresolved' => true]))->toBe(0);

    Queue::assertPushed(ResolveLibraryTracks::class, fn (ResolveLibraryTracks $job): bool => array_column($job->tracks, 'externalId') === ['video000001']);
});
```

`EnrichmentFactory` defaults to `subject_type` `recording`, `fetched_at` now. `RecordingResolutionFactory` defaults to provider YouTube Music and creates a recording for `Resolved`.

`tests/Unit/Actions/RecordEnrichmentCoverageTest.php`, append (read the file for its telemetry binding; reuse it):

```php
it('measures Last.fm tags, similar tracks and popularity', function (): void {
    $telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $telemetry);
    $tagged = Recording::factory()->create(['lastfm_listeners' => 10]);
    $throughArtist = Recording::factory()->create();
    Recording::factory()->create();
    $tag = Tag::named('chill', false);
    DB::table('recording_tags')->insert(['recording_id' => $tagged->id, 'tag_id' => $tag->id, 'source' => 'lastfm', 'weight' => 50]);
    $artist = Contributor::factory()->create();
    RecordingContributor::query()->create(['recording_id' => $throughArtist->id, 'contributor_id' => $artist->id, 'credit_type' => CreditType::Artist, 'role' => '', 'source' => MetadataSource::MusicBrainz, 'credit_attributes' => []]);
    DB::table('contributor_tags')->insert(['contributor_id' => $artist->id, 'tag_id' => $tag->id, 'source' => 'lastfm', 'weight' => 50]);
    SimilarRecording::query()->create(['recording_id' => $tagged->id, 'title' => 'x', 'artist_name' => 'y', 'match' => 0.5, 'source' => MetadataSource::LastFm]);

    resolve(RecordEnrichmentCoverage::class)->handle();

    expect(round($telemetry->coverage['lastfm_tags'], 2))->toBe(0.67)
        ->and(round($telemetry->coverage['similar'], 2))->toBe(0.33)
        ->and(round($telemetry->coverage['popularity'], 2))->toBe(0.33);
});
```

Imports for this test: `App\Enums\CreditType`, `App\Enums\MetadataSource`, `App\Models\Contributor`, `App\Models\Recording`, `App\Models\RecordingContributor`, `App\Models\SimilarRecording`, `App\Models\Tag`, `Illuminate\Support\Facades\DB`.

- [ ] **Step 2: Run them to verify they fail**

Run each: `tests/Feature/Commands/MetadataCommandsTest.php`, `tests/Unit/Actions/RecordEnrichmentCoverageTest.php`.
Expected: FAIL.

- [ ] **Step 3: Describe what was never described, without duplicates**

In `app/Console/Commands/RetryDueMetadataCommand.php`:
- `handle(RecordEnrichmentCoverage $coverage, DescribeRecording $describeRecording, DescribeContributor $describeContributor): int`.
- In the existing `Enrichment` loop, dispatch once per subject and source: keep `$dispatched = [];` before the loop and, inside, skip when `isset($dispatched[$key = "{$enrichment->subject_type}:{$enrichment->subject_key}:{$enrichment->source->value}"])`, else set `$dispatched[$key] = true;` before dispatching. Keep the `next_attempt_at` update for every row.
- After the resolution loop, before `$coverage->handle()`:

```php
        foreach ($describeRecording->sources() as $source) {
            $this->undescribedRecordings($source)->lazyById()->each(function (Recording $recording) use ($source, &$retried): void {
                $retried++;
                EnrichRecording::dispatch($recording->id, $source);
            });
        }

        Contributor::query()
            ->whereExists(fn (QueryBuilder $credits): QueryBuilder => $credits->select(DB::raw(1))
                ->from('recording_contributors')
                ->whereColumn('recording_contributors.contributor_id', 'contributors.id')
                ->where('credit_type', CreditType::Artist->value))
            ->lazyById()
            ->each(function (Contributor $contributor) use ($describeContributor, &$retried): void {
                foreach ($describeContributor->undescribedSources($contributor) as $source) {
                    $retried++;
                    EnrichContributor::dispatch($contributor->id, $source);
                }
            });
```

- Add:

```php
    /**
     * The recordings this source can identify and never answered about.
     *
     * @return Builder<Recording>
     */
    private function undescribedRecordings(MetadataSource $source): Builder
    {
        return Recording::query()
            ->when($source === MetadataSource::CreditsFm, fn (Builder $query): Builder => $query->whereNotNull('isrc'))
            ->when($source === MetadataSource::MusicBrainz, fn (Builder $query): Builder => $query->whereNotNull('mbid'))
            ->whereNotExists(fn (QueryBuilder $enrichments): QueryBuilder => $enrichments->select(DB::raw(1))
                ->from('enrichments')
                ->where('subject_type', Enrichment::RECORDING)
                ->where('source', $source->value)
                ->whereRaw('enrichments.subject_key = recordings.id::text'));
    }
```

Imports: `App\Actions\DescribeContributor`, `App\Actions\DescribeRecording`, `App\Enums\CreditType`, `App\Enums\MetadataSource`, `App\Jobs\EnrichContributor`, `App\Models\Contributor`, `App\Models\Recording`, `Illuminate\Database\Eloquent\Builder`, `Illuminate\Database\Query\Builder as QueryBuilder`, `Illuminate\Support\Facades\DB`.

Update its `#[Description]` to "Fetch again the metadata that failed, was missing or went stale, and what a source never described".

- [ ] **Step 4: Queue the unresolved tracks on demand**

`app/Actions/QueueTrackResolution.php`: add `bool $unresolved = false` to `handle()` and replace the `->when(! $refresh, …)` clause with:

```php
            ->when($unresolved, fn (Builder $query): Builder => $query->whereIn(
                'youtube_video_id',
                RecordingResolution::query()
                    ->where('provider', Provider::YouTubeMusic)
                    ->whereIn('status', [ResolutionStatus::NotFound, ResolutionStatus::Pending])
                    ->select('external_id'),
            ))
            ->when(! $refresh && ! $unresolved, fn (Builder $query): Builder => $query->whereNotIn(
                'youtube_video_id',
                RecordingResolution::query()->where('provider', Provider::YouTubeMusic)->select('external_id'),
            ))
```

Document the parameter in the method docblock: "`$unresolved` queues only the tracks not found or still pending, now rather than when they are due."

`app/Console/Commands/EnrichMetadataCommand.php`:

```php
#[Signature('metadata:enrich {--refresh : Resolve again the tracks already resolved} {--unresolved : Resolve again, now, the tracks not found or still pending}')]
#[Description('Queue the library for metadata enrichment (credits.fm, MusicBrainz, Last.fm)')]
```

and `$queued = $queue->handle(refresh: (bool) $this->option('refresh'), unresolved: (bool) $this->option('unresolved'));`.

- [ ] **Step 5: Measure the new facets**

In `app/Actions/RecordEnrichmentCoverage.php`, after the `genres` facet (imports `App\Models\SimilarRecording`, `Illuminate\Database\Query\Builder as QueryBuilder`):

```php
        $lastFmTagged = Recording::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereExists(fn (QueryBuilder $tags): QueryBuilder => $tags->select(DB::raw(1))
                    ->from('recording_tags')
                    ->whereColumn('recording_tags.recording_id', 'recordings.id')
                    ->where('recording_tags.source', MetadataSource::LastFm->value))
                ->orWhereExists(fn (QueryBuilder $artists): QueryBuilder => $artists->select(DB::raw(1))
                    ->from('recording_contributors')
                    ->join('contributor_tags', 'contributor_tags.contributor_id', '=', 'recording_contributors.contributor_id')
                    ->whereColumn('recording_contributors.recording_id', 'recordings.id')
                    ->where('recording_contributors.credit_type', CreditType::Artist->value)
                    ->where('contributor_tags.source', MetadataSource::LastFm->value)))
            ->count();

        $this->telemetry->coverage('lastfm_tags', $lastFmTagged / $recordings);
        $this->telemetry->coverage('similar', SimilarRecording::query()->distinct()->count('recording_id') / $recordings);
        $this->telemetry->coverage('popularity', Recording::query()->whereNotNull('lastfm_listeners')->count() / $recordings);
```

`Builder` here is `Illuminate\Database\Eloquent\Builder` (import it if absent) and `MetadataSource` must be imported.

- [ ] **Step 6: Run the tests to verify they pass**

Run each: `tests/Feature/Commands/MetadataCommandsTest.php`, `tests/Unit/Actions/QueueTrackResolutionTest.php`, `tests/Unit/Actions/RecordEnrichmentCoverageTest.php`.
Expected: PASS.

- [ ] **Step 7: Analyse, format, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyze app/Console/Commands app/Actions tests/Feature/Commands tests/Unit/Actions
git add app/Console/Commands app/Actions tests/Feature/Commands tests/Unit/Actions
git commit -m "feat(enrichment): fill last.fm in for the existing library and measure it"
```

---

### Task 10: Whole-suite verification and a real run

**Files:** none (verification only).

- [ ] **Step 1: Run every check**

```bash
./vendor/bin/pest --parallel
composer test:type-coverage
vendor/bin/phpstan analyze
vendor/bin/pint --test --format agent
```

Expected: all green. Fix anything red in the task that owns it, with its own commit.

- [ ] **Step 2: Run it for real on the local copy of production**

With `LASTFM_API_KEY` in `.env` and `DEV_QUEUE_ENRICHMENT=false`:

```bash
php artisan metadata:charts
php artisan queue:work --queue=enrichment --stop-when-empty
```

Then with the `database-query` tool: `SELECT chart, count(*), count(recording_id) FROM chart_entries JOIN chart_snapshots ON chart_snapshots.id = chart_snapshot_id GROUP BY chart`.
Expected: four charts, 200 entries each.

Then describe one name-only track end to end:

```bash
php artisan metadata:enrich --unresolved
php artisan queue:work --queue=enrichment --max-jobs=20 --stop-when-empty
```

Check with `database-query`: `SELECT method, count(*) FROM recording_resolutions GROUP BY method` shows `lastfm` rows, and `SELECT count(*) FROM recording_tags WHERE source = 'lastfm'` is above 0.

- [ ] **Step 3: Report**

Report to the user: the suite results, the local run numbers, and the deployment steps (they decide when): `cloud environment:variables` to add `LASTFM_API_KEY` to production, deploy, `cloud command:run production --cmd="php artisan metadata:enrich --unresolved"`, then the next daily `metadata:retry-due` describes the rest.
