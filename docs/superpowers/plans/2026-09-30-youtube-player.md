# YouTube Player Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fake playback timer with real YouTube IFrame playback driven by Sonder's controls, and record one row per listen for the future suggestion algorithm.

**Architecture:** A framework-free `PlayerTransport` interface (YouTube implementation + in-memory fake) sits under `usePlayer`, which becomes a `createPlayer(deps)` factory exposed through `usePlayer()` (injectable for tests). A pure `listenTracker` turns playback into listen payloads; `listenSender` posts them to a new `POST /listens` endpoint that stores them idempotently in a `listens` table and counts them in OpenTelemetry.

**Tech Stack:** Laravel 13, Pest 5, Inertia v3 + Vue 3.5, Wayfinder, Vite+ (`vp test` = Vitest 5, browser mode with Playwright), `vitest-browser-vue`, keepsuit/laravel-opentelemetry.

**Spec:** `docs/superpowers/specs/2026-09-30-youtube-player-design.md`

## Global Constraints

- PHP: `declare(strict_types=1);`, `final` / `final readonly` classes, constructor promotion, explicit return types, curly braces everywhere, PHPDoc over inline comments (existing style in `app/`).
- Actions live in `app/Actions`, no suffix, single `handle()` method.
- After any PHP change: `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyze --level 8 <changed files>`.
- Backend tests: `./vendor/bin/pest --tia --parallel` (project rule), narrow with a file path while iterating.
- Frontend tests: `vp test` (never `npm`/`npx`; use `bun`/`bunx` for packages). Front lint/types before finishing: `bun run test:lint` and `bun run test:types`.
- Frontend routes come from Wayfinder controller objects (`import ListenController from '@/actions/App/Http/Controllers/ListenController'`), never `@/routes/*`, never hardcoded URLs.
- UI copy keys are English; every new key gets a French entry in `lang/fr_BE.json` (keys removed from the UI are removed from that file).
- The player iframe host must use `https://www.youtube.com` (never `youtube-nocookie.com`), `controls: 0`, and live 1px offscreen with `opacity: 0` — never `display: none` / `visibility: hidden`.
- No real YouTube network calls in automated tests.
- Commits: Conventional Commits, lowercase imperative subject, **no** `Co-Authored-By` or any AI attribution (project rule in `.ai/rules/general.md`). Commits are GPG-signed; if signing fails, stop and ask the user to unlock the key.
- New dev dependencies (approved): `vitest-browser-vue` and `@vitest/browser-playwright@5.0.1`. No other dependency changes.

## Review Focus

- **Background tab timer throttling:** Chrome throttles hidden-tab timers (up to one tick per minute), so a single 250ms poll can see a 60s media jump; that must still count as listening, not a seek. Pinned in Task 2 (`recordProgress` compares the media delta with the wall-clock delta).
- **Track without a video id** in the queue (`videoId: null`): must be skipped without a crash or a listen; an all-null queue stops cleanly. Pinned in Task 4.
- **Clicking play before the YouTube API is ready:** must be a no-op, then the current track is cued once ready. Pinned in Task 4.
- **Rapid double skip:** each track loaded with autoplay produces its own `skipped` listen even at 0s. Pinned in Task 4.
- **Reload mid-track:** restores the track paused at the saved position and keeps the original `origin` for the resumed listen. Pinned in Task 4.

## Deviations from the spec (decided while planning)

- `listenSender.send()` uses Inertia's shared HTTP client (`http.getClient().request()` from `@inertiajs/vue3`) instead of the `useHttp` hook: same client, same automatic `X-XSRF-TOKEN`, but no form state, which a module-level store does not need.
- `PlayerTransport` gains an `unavailable` event (API script failed to load) and a `destroy()` method.
- Seek detection compares media progress with wall-clock time instead of a fixed 2s threshold (see Review Focus).
- A `422` on a listen submission is logged in the debug panel as "not recorded", without the validation details (the sender only reports success or failure).
- `CommandPalette` currently only jumps within the queue (`jumpTo` → `Queue`/`Jumped`); nothing starts a track from a search yet, so `ListenOrigin::Search` has no caller until a track search exists.

## File Structure

| Path | Responsibility |
|---|---|
| `app/Enums/ListenOrigin.php`, `app/Enums/ListenEndReason.php` | Backed enums, exported to TypeScript |
| `database/migrations/*_create_listens_table.php` | `listens` table |
| `app/Models/Listen.php`, `database/factories/ListenFactory.php` | Model + factory |
| `app/Http/Requests/CreateListenRequest.php` | Validation incl. listened-vs-wall-time check |
| `app/Actions/RecordListen.php` | Idempotent insert + OTel counter |
| `app/Http/Controllers/ListenController.php` | `store` → 204 |
| `routes/web.php` | `POST /listens` |
| `tests/Feature/Controllers/ListenControllerTest.php` | Backend tests |
| `resources/js/lib/player/listenTracker.ts` (+ `.test.ts`) | Pure listen accounting |
| `resources/js/lib/player/transport.ts` | `PlayerTransport` interface + typed emitter |
| `resources/js/lib/player/fakeTransport.ts` | Test double |
| `resources/js/lib/player/listenSender.ts` (+ `.test.ts`) | POST + unload fetch |
| `resources/js/lib/player/youtubeTransport.ts` | IFrame API implementation |
| `resources/js/composables/usePlayer.ts` (+ `.test.ts`) | `createPlayer` store, `usePlayer()` |
| `resources/js/components/shell/PlayerHost.vue` (+ `.browser.test.ts`) | Hidden iframe host |
| `resources/js/components/shell/PlayerBar.vue` (+ `.browser.test.ts`) | Seek, real duration, no "preview" label |
| `resources/js/components/shell/PlayerDebugPanel.vue` (+ `.browser.test.ts`) | Debug view |
| `resources/js/components/shell/QueuePanel.vue` | "Debug" link |
| `resources/js/layouts/AppLayout.vue` | Mounts `PlayerHost` |
| `resources/js/pages/discover/Index.vue`, `resources/js/pages/playlist/Show.vue` | Pass origins |
| `vite.config.ts`, `package.json` | Test projects, `test` script |
| `lang/fr_BE.json` | Copy |
| `public/player-test.html` | Deleted (untracked throwaway) |

---

### Task 1: Listens API

**Files:**
- Create: `app/Enums/ListenOrigin.php`, `app/Enums/ListenEndReason.php`, `database/migrations/<timestamp>_create_listens_table.php`, `app/Models/Listen.php`, `database/factories/ListenFactory.php`, `app/Http/Requests/CreateListenRequest.php`, `app/Actions/RecordListen.php`, `app/Http/Controllers/ListenController.php`
- Modify: `routes/web.php`, `app/Models/User.php` (relation), `resources/js/types/generated.d.ts` (regenerated)
- Test: `tests/Feature/Controllers/ListenControllerTest.php`

**Interfaces:**
- Produces: route `listen.store` (`POST /listens`), JSON body keys `id, youtube_video_id, title, artists, youtube_playlist_id, origin, end_reason, started_at, ended_at, position_seconds, listened_seconds, duration_seconds`; `204` on success, `422` JSON on validation failure. TypeScript unions `App.Enums.ListenOrigin = 'playlist' | 'search' | 'suggestion' | 'queue' | 'autoplay'` and `App.Enums.ListenEndReason = 'ended' | 'skipped' | 'previous' | 'jumped' | 'replaced' | 'picked' | 'error' | 'abandoned'`. Wayfinder `ListenController.store()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Controllers/ListenControllerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Listen;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function listenPayload(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'youtube_video_id' => 'UcOUJM08bYk',
        'title' => 'Survival',
        'artists' => 'Muse',
        'youtube_playlist_id' => 'PL1',
        'origin' => 'playlist',
        'end_reason' => 'skipped',
        'started_at' => now()->subSeconds(30)->toIso8601String(),
        'ended_at' => now()->toIso8601String(),
        'position_seconds' => 28,
        'listened_seconds' => 28,
        'duration_seconds' => 258,
        ...$overrides,
    ];
}

it('records a listen for the signed-in user', function (): void {
    $user = User::factory()->create();
    $payload = listenPayload();

    $response = $this->actingAs($user)->postJson(route('listen.store'), $payload);

    $response->assertNoContent();
    $listen = Listen::query()->findOrFail($payload['id']);
    expect($listen->user_id)->toBe($user->id)
        ->and($listen->youtube_video_id)->toBe('UcOUJM08bYk')
        ->and($listen->origin->value)->toBe('playlist')
        ->and($listen->end_reason->value)->toBe('skipped')
        ->and($listen->listened_seconds)->toBe(28)
        ->and($listen->duration_seconds)->toBe(258);
});

it('ignores the same listen sent twice', function (): void {
    $user = User::factory()->create();
    $payload = listenPayload();

    $this->actingAs($user)->postJson(route('listen.store'), $payload)->assertNoContent();
    $this->actingAs($user)->postJson(route('listen.store'), $payload)->assertNoContent();

    expect(Listen::query()->count())->toBe(1);
});

it('never takes the owner from the request body', function (): void {
    $user = User::factory()->create();
    $someoneElse = User::factory()->create();
    $payload = listenPayload(['user_id' => $someoneElse->id]);

    $this->actingAs($user)->postJson(route('listen.store'), $payload)->assertNoContent();

    expect(Listen::query()->findOrFail($payload['id'])->user_id)->toBe($user->id);
});

it('redirects guests to the login page', function (): void {
    $this->post(route('listen.store'), listenPayload())->assertRedirect(route('login'));

    expect(Listen::query()->count())->toBe(0);
});

it('rejects invalid listens', function (array $overrides, string $field): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('listen.store'), listenPayload($overrides));

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Listen::query()->count())->toBe(0);
})->with([
    'missing id' => [['id' => null], 'id'],
    'id is not a uuid' => [['id' => 'nope'], 'id'],
    'video id with spaces' => [['youtube_video_id' => 'not a video'], 'youtube_video_id'],
    'video id too long' => [['youtube_video_id' => str_repeat('a', 12)], 'youtube_video_id'],
    'unknown origin' => [['origin' => 'radio'], 'origin'],
    'unknown end reason' => [['end_reason' => 'bored'], 'end_reason'],
    'ends before it starts' => [['started_at' => now()->toIso8601String(), 'ended_at' => now()->subMinute()->toIso8601String()], 'ended_at'],
    'ends in the future' => [['ended_at' => now()->addMinutes(5)->toIso8601String()], 'ended_at'],
    'negative position' => [['position_seconds' => -1], 'position_seconds'],
    'listened longer than the listen lasted' => [['listened_seconds' => 120], 'listened_seconds'],
]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Controllers/ListenControllerTest.php`
Expected: FAIL — `Route [listen.store] not defined.`

- [ ] **Step 3: Create the enums**

`app/Enums/ListenOrigin.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How a listen started.
 */
#[TypeScript]
enum ListenOrigin: string
{
    case Playlist = 'playlist';
    case Search = 'search';
    case Suggestion = 'suggestion';
    case Queue = 'queue';
    case Autoplay = 'autoplay';
}
```

`app/Enums/ListenEndReason.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How a listen was left.
 */
#[TypeScript]
enum ListenEndReason: string
{
    case Ended = 'ended';
    case Skipped = 'skipped';
    case Previous = 'previous';
    case Jumped = 'jumped';
    case Replaced = 'replaced';
    case Picked = 'picked';
    case Error = 'error';
    case Abandoned = 'abandoned';
}
```

- [ ] **Step 4: Create the migration, model and factory**

Run: `php artisan make:model Listen --migration --factory --no-interaction`

Replace the generated migration body:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('youtube_video_id')->index();
            $table->string('title');
            $table->string('artists');
            $table->string('youtube_playlist_id')->nullable();
            $table->string('origin');
            $table->string('end_reason');
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->unsignedInteger('position_seconds');
            $table->unsignedInteger('listened_seconds');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listens');
    }
};
```

`app/Models/Listen.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListenEndReason;
use App\Enums\ListenOrigin;
use Carbon\CarbonInterface;
use Database\Factories\ListenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One listen of one track. Linked to tracks by YouTube ids rather than
 * foreign keys: the library sync deletes and recreates tracks.
 *
 * @property-read string $id
 * @property-read string $user_id
 * @property-read string $youtube_video_id
 * @property-read string $title
 * @property-read string $artists
 * @property-read string|null $youtube_playlist_id
 * @property-read ListenOrigin $origin
 * @property-read ListenEndReason $end_reason
 * @property-read CarbonInterface $started_at
 * @property-read CarbonInterface $ended_at
 * @property-read int $position_seconds
 * @property-read int $listened_seconds
 * @property-read int|null $duration_seconds
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $user
 */
final class Listen extends Model
{
    /** @use HasFactory<ListenFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'user_id',
        'youtube_video_id',
        'title',
        'artists',
        'youtube_playlist_id',
        'origin',
        'end_reason',
        'started_at',
        'ended_at',
        'position_seconds',
        'listened_seconds',
        'duration_seconds',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'origin' => ListenOrigin::class,
            'end_reason' => ListenEndReason::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'position_seconds' => 'integer',
            'listened_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`database/factories/ListenFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListenEndReason;
use App\Enums\ListenOrigin;
use App\Models\Listen;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listen>
 */
final class ListenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $duration = fake()->numberBetween(120, 360);
        $listened = fake()->numberBetween(0, $duration);
        $startedAt = now()->subMinutes(fake()->numberBetween(5, 600));

        return [
            'user_id' => User::factory(),
            'youtube_video_id' => fake()->regexify('[A-Za-z0-9_-]{11}'),
            'title' => fake()->sentence(3),
            'artists' => fake()->name(),
            'youtube_playlist_id' => 'PL'.fake()->regexify('[A-Za-z0-9]{16}'),
            'origin' => ListenOrigin::Playlist,
            'end_reason' => ListenEndReason::Ended,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addSeconds($listened),
            'position_seconds' => $listened,
            'listened_seconds' => $listened,
            'duration_seconds' => $duration,
        ];
    }
}
```

In `app/Models/User.php`, add next to the existing relations (import `Illuminate\Database\Eloquent\Relations\HasMany` if not already imported):

```php
    /**
     * @return HasMany<Listen, $this>
     */
    public function listens(): HasMany
    {
        return $this->hasMany(Listen::class);
    }
```

- [ ] **Step 5: Create the request**

`app/Http/Requests/CreateListenRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListenEndReason;
use App\Enums\ListenOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CreateListenRequest extends FormRequest
{
    /**
     * Slack for a listened time a little over the wall-clock duration
     * (timer granularity, rounding on the client).
     */
    private const int LISTENED_SLACK_SECONDS = 5;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'youtube_video_id' => ['required', 'string', 'max:11', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title' => ['required', 'string', 'max:255'],
            'artists' => ['required', 'string', 'max:255'],
            'youtube_playlist_id' => ['nullable', 'string', 'max:64'],
            'origin' => ['required', Rule::enum(ListenOrigin::class)],
            'end_reason' => ['required', Rule::enum(ListenEndReason::class)],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at', 'before_or_equal:'.now()->addMinute()->toIso8601String()],
            'position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'listened_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['started_at', 'ended_at', 'listened_seconds'])) {
                    return;
                }

                $lasted = CarbonImmutable::parse($this->string('started_at')->toString())
                    ->diffInSeconds(CarbonImmutable::parse($this->string('ended_at')->toString()));

                if ($this->integer('listened_seconds') > $lasted + self::LISTENED_SLACK_SECONDS) {
                    $validator->errors()->add('listened_seconds', __('The listened time is longer than the listen itself.'));
                }
            },
        ];
    }
}
```

- [ ] **Step 6: Create the action**

`app/Actions/RecordListen.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Listen;
use App\Models\User;
use Carbon\CarbonImmutable;
use Keepsuit\LaravelOpenTelemetry\Facades\Meter;

final readonly class RecordListen
{
    /**
     * Store a listen. The client generates the id, so a listen sent twice
     * (page unload racing a track change) is stored once.
     *
     * @param  array{id: string, youtube_video_id: string, title: string, artists: string, youtube_playlist_id?: string|null, origin: string, end_reason: string, started_at: string, ended_at: string, position_seconds: int, listened_seconds: int, duration_seconds?: int|null}  $attributes
     */
    public function handle(User $user, array $attributes): void
    {
        $now = now();

        $inserted = Listen::query()->insertOrIgnore([
            'id' => $attributes['id'],
            'user_id' => $user->id,
            'youtube_video_id' => $attributes['youtube_video_id'],
            'title' => $attributes['title'],
            'artists' => $attributes['artists'],
            'youtube_playlist_id' => $attributes['youtube_playlist_id'] ?? null,
            'origin' => $attributes['origin'],
            'end_reason' => $attributes['end_reason'],
            'started_at' => CarbonImmutable::parse($attributes['started_at']),
            'ended_at' => CarbonImmutable::parse($attributes['ended_at']),
            'position_seconds' => $attributes['position_seconds'],
            'listened_seconds' => $attributes['listened_seconds'],
            'duration_seconds' => $attributes['duration_seconds'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return;
        }

        Meter::counter('sonder.listens', '{listen}', 'Listens recorded, by end reason and origin')
            ->add(1, ['end_reason' => $attributes['end_reason'], 'origin' => $attributes['origin']]);
    }
}
```

- [ ] **Step 7: Create the controller and route**

`app/Http/Controllers/ListenController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordListen;
use App\Http\Requests\CreateListenRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

final readonly class ListenController
{
    public function store(CreateListenRequest $request, #[CurrentUser] User $user, RecordListen $record): Response
    {
        /** @var array{id: string, youtube_video_id: string, title: string, artists: string, youtube_playlist_id?: string|null, origin: string, end_reason: string, started_at: string, ended_at: string, position_seconds: int, listened_seconds: int, duration_seconds?: int|null} $attributes */
        $attributes = $request->validated();

        $record->handle($user, $attributes);

        return response()->noContent();
    }
}
```

In `routes/web.php`, add `use App\Http\Controllers\ListenController;` with the other imports, and inside the `Route::middleware(['auth', 'verified'])` group, after the playlists block:

```php
    // Listens...
    Route::post('listens', [ListenController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('listen.store');
```

- [ ] **Step 8: Migrate and run the tests**

Run: `php artisan migrate --no-interaction && ./vendor/bin/pest tests/Feature/Controllers/ListenControllerTest.php`
Expected: PASS (all cases, including the 10 dataset rows).

- [ ] **Step 9: Regenerate TypeScript types and Wayfinder actions**

Run: `php artisan typescript:transform && php artisan wayfinder:generate --with-form`
Expected: `resources/js/types/generated.d.ts` contains `export type ListenOrigin = 'playlist' | 'search' | 'suggestion' | 'queue' | 'autoplay';` and `export type ListenEndReason = ...`; `resources/js/actions/App/Http/Controllers/ListenController.ts` exists (gitignored).

- [ ] **Step 10: Format, analyse, full backend suite**

Run: `vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyze --level 8 app/Enums/ListenOrigin.php app/Enums/ListenEndReason.php app/Models/Listen.php app/Models/User.php app/Http/Requests/CreateListenRequest.php app/Actions/RecordListen.php app/Http/Controllers/ListenController.php database/factories/ListenFactory.php && ./vendor/bin/pest --tia --parallel`
Expected: pint passed, phpstan 0 errors, all tests pass.

- [ ] **Step 11: Commit**

```bash
git add app/Enums/ListenOrigin.php app/Enums/ListenEndReason.php app/Models/Listen.php app/Models/User.php database/migrations/*_create_listens_table.php database/factories/ListenFactory.php app/Http/Requests/CreateListenRequest.php app/Actions/RecordListen.php app/Http/Controllers/ListenController.php routes/web.php resources/js/types/generated.d.ts tests/Feature/Controllers/ListenControllerTest.php
git commit -m "feat(player): record listens through a new listens endpoint"
```

---

### Task 2: Front test tooling + listen tracker

**Files:**
- Modify: `package.json`, `vite.config.ts`
- Create: `resources/js/lib/player/listenTracker.ts`
- Test: `resources/js/lib/player/listenTracker.test.ts`

**Interfaces:**
- Consumes: `App.Enums.ListenOrigin`, `App.Enums.ListenEndReason` (Task 1).
- Produces:
  - `type ListenContext = { videoId: string; title: string; artists: string; playlistId: string | null; origin: App.Enums.ListenOrigin }`
  - `type Listen = ListenContext & { id: string; startedAt: number; listenedSeconds: number; position: number; lastSample: { position: number; at: number } | null }`
  - `type ListenPayload = { id: string; youtube_video_id: string; title: string; artists: string; youtube_playlist_id: string | null; origin: App.Enums.ListenOrigin; end_reason: App.Enums.ListenEndReason; started_at: string; ended_at: string; position_seconds: number; listened_seconds: number; duration_seconds: number | null }`
  - `startListen(context: ListenContext, nowMs: number, createId?: () => string): Listen`
  - `recordProgress(listen: Listen, position: number, isPlaying: boolean, nowMs: number): void`
  - `markSeek(listen: Listen, position: number, nowMs: number): void`
  - `finishListen(listen: Listen, reason: App.Enums.ListenEndReason, nowMs: number, durationSeconds: number | null): ListenPayload`
  - `vp test --project unit` / `--project browser` projects; `bun run test` script.

- [ ] **Step 1: Install the test dependencies**

Run: `bun add -d vitest-browser-vue @vitest/browser-playwright@5.0.1 && bunx playwright install chromium`
Expected: both appear in `devDependencies`; Chromium is installed (already present if Pest browser tests ran before).

- [ ] **Step 2: Add the test projects**

In `vite.config.ts`, add `import { playwright } from 'vite-plus/test/browser-playwright';` with the other imports, keep Vue devtools out of test runs, and add a `test` block. Replace the `vueDevTools({...})` entry in `plugins` with:

```ts
        ...(process.env.VITEST
            ? []
            : [
                  vueDevTools({
                      launchEditor: 'phpstorm',
                      componentInspector: true,
                      appendTo: 'resources/js/app.ts',
                  }),
              ]),
```

and add at the top level of the `defineConfig({...})` object:

```ts
    test: {
        projects: [
            {
                extends: true,
                test: {
                    name: 'unit',
                    environment: 'node',
                    include: ['resources/js/**/*.test.ts'],
                    exclude: ['resources/js/**/*.browser.test.ts'],
                },
            },
            {
                extends: true,
                test: {
                    name: 'browser',
                    include: ['resources/js/**/*.browser.test.ts'],
                    browser: {
                        enabled: true,
                        headless: true,
                        provider: playwright(),
                        instances: [{ browser: 'chromium' }],
                    },
                },
            },
        ],
    },
```

In `package.json` `scripts`, add: `"test": "vp test"`.

- [ ] **Step 3: Write the failing tracker tests**

`resources/js/lib/player/listenTracker.test.ts`:

```ts
import { describe, expect, it } from 'vite-plus/test';
import {
    finishListen,
    markSeek,
    recordProgress,
    startListen,
} from '@/lib/player/listenTracker';

const T0 = Date.parse('2026-10-01T10:00:00Z');

function aListen() {
    return startListen(
        {
            videoId: 'abc',
            title: 'Survival',
            artists: 'Muse',
            playlistId: 'PL1',
            origin: 'playlist',
        },
        T0,
        () => 'listen-1',
    );
}

/** Plays `seconds` of media in 250ms steps, media and wall clock in sync. */
function play(listen: ReturnType<typeof aListen>, from: number, seconds: number, at: number): number {
    let position = from;
    let now = at;

    for (let step = 0; step < seconds * 4; step++) {
        position += 0.25;
        now += 250;
        recordProgress(listen, position, true, now);
    }

    return now;
}

describe('listenTracker', () => {
    it('counts media time played while playing', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);

        const now = play(listen, 0, 10, T0);

        expect(finishListen(listen, 'skipped', now, 200).listened_seconds).toBe(10);
    });

    it('adds nothing while paused', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);
        let now = play(listen, 0, 5, T0);

        recordProgress(listen, 5, false, now + 30_000);
        now = play(listen, 5, 5, now + 30_000);

        expect(finishListen(listen, 'ended', now, 200).listened_seconds).toBe(10);
    });

    it('does not count a seek as listening', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);
        let now = play(listen, 0, 5, T0);

        markSeek(listen, 120, now);
        now = play(listen, 120, 5, now);

        const payload = finishListen(listen, 'skipped', now, 200);
        expect(payload.listened_seconds).toBe(10);
        expect(payload.position_seconds).toBe(125);
    });

    it('does not count a forward jump the wall clock cannot explain', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);

        recordProgress(listen, 90, true, T0 + 250);

        expect(finishListen(listen, 'skipped', T0 + 250, 200).listened_seconds).toBe(0);
    });

    it('counts a long gap when the wall clock moved as much (throttled background tab)', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);

        recordProgress(listen, 60, true, T0 + 60_000);

        expect(finishListen(listen, 'ended', T0 + 60_000, 200).listened_seconds).toBe(60);
    });

    it('builds the payload the API expects', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);
        const now = play(listen, 0, 3, T0);

        expect(finishListen(listen, 'picked', now, 257.6)).toEqual({
            id: 'listen-1',
            youtube_video_id: 'abc',
            title: 'Survival',
            artists: 'Muse',
            youtube_playlist_id: 'PL1',
            origin: 'playlist',
            end_reason: 'picked',
            started_at: '2026-10-01T10:00:00.000Z',
            ended_at: '2026-10-01T10:00:03.000Z',
            position_seconds: 3,
            listened_seconds: 3,
            duration_seconds: 258,
        });
    });

    it('never reports more listening than the wall clock allows', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);
        recordProgress(listen, 1.5, true, T0 + 250);

        expect(finishListen(listen, 'skipped', T0 + 250, null).listened_seconds).toBe(1);
    });

    it('reports an unknown duration as null', () => {
        expect(finishListen(aListen(), 'error', T0, 0).duration_seconds).toBeNull();
    });
});
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `vp test --project unit resources/js/lib/player/listenTracker.test.ts`
Expected: FAIL — cannot resolve `@/lib/player/listenTracker`.

- [ ] **Step 5: Implement the tracker**

`resources/js/lib/player/listenTracker.ts`:

```ts
/**
 * Pure accounting for one listen: how long the track was actually heard,
 * where it was left, and the payload the listens endpoint expects.
 */

/** Media may run this much ahead of the wall clock before it counts as a seek. */
const SEEK_TOLERANCE_SECONDS = 1.5;

export type ListenContext = {
    videoId: string;
    title: string;
    artists: string;
    playlistId: string | null;
    origin: App.Enums.ListenOrigin;
};

export type Listen = ListenContext & {
    id: string;
    startedAt: number;
    listenedSeconds: number;
    position: number;
    lastSample: { position: number; at: number } | null;
};

export type ListenPayload = {
    id: string;
    youtube_video_id: string;
    title: string;
    artists: string;
    youtube_playlist_id: string | null;
    origin: App.Enums.ListenOrigin;
    end_reason: App.Enums.ListenEndReason;
    started_at: string;
    ended_at: string;
    position_seconds: number;
    listened_seconds: number;
    duration_seconds: number | null;
};

export function startListen(
    context: ListenContext,
    nowMs: number,
    createId: () => string = () => crypto.randomUUID(),
): Listen {
    return {
        ...context,
        id: createId(),
        startedAt: nowMs,
        listenedSeconds: 0,
        position: 0,
        lastSample: null,
    };
}

/**
 * Feed a position sample. Media progress counts as listening only while
 * playing and only as far as the wall clock explains it, so a seek never
 * counts while a throttled background timer (one tick a minute) still does.
 */
export function recordProgress(
    listen: Listen,
    position: number,
    isPlaying: boolean,
    nowMs: number,
): void {
    if (isPlaying && listen.lastSample !== null) {
        const mediaDelta = position - listen.lastSample.position;
        const wallDelta = (nowMs - listen.lastSample.at) / 1000;

        if (mediaDelta > 0 && mediaDelta <= wallDelta + SEEK_TOLERANCE_SECONDS) {
            listen.listenedSeconds += mediaDelta;
        }
    }

    listen.lastSample = { position, at: nowMs };
    listen.position = position;
}

export function markSeek(listen: Listen, position: number, nowMs: number): void {
    listen.lastSample = { position, at: nowMs };
    listen.position = position;
}

export function finishListen(
    listen: Listen,
    reason: App.Enums.ListenEndReason,
    nowMs: number,
    durationSeconds: number | null,
): ListenPayload {
    const endedAt = Math.max(nowMs, listen.startedAt);
    const lastedSeconds = Math.floor((endedAt - listen.startedAt) / 1000);

    return {
        id: listen.id,
        youtube_video_id: listen.videoId,
        title: listen.title,
        artists: listen.artists,
        youtube_playlist_id: listen.playlistId,
        origin: listen.origin,
        end_reason: reason,
        started_at: new Date(listen.startedAt).toISOString(),
        ended_at: new Date(endedAt).toISOString(),
        position_seconds: Math.max(0, Math.floor(listen.position)),
        listened_seconds: Math.min(Math.floor(listen.listenedSeconds), lastedSeconds),
        duration_seconds:
            durationSeconds !== null && durationSeconds > 0
                ? Math.round(durationSeconds)
                : null,
    };
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vp test --project unit resources/js/lib/player/listenTracker.test.ts`
Expected: PASS (8 tests).

- [ ] **Step 7: Commit**

```bash
git add package.json bun.lock vite.config.ts resources/js/lib/player/listenTracker.ts resources/js/lib/player/listenTracker.test.ts
git commit -m "test(player): set up vitest projects and add the listen tracker"
```

---

### Task 3: Transport contract, fake transport and listen sender

**Files:**
- Create: `resources/js/lib/player/transport.ts`, `resources/js/lib/player/fakeTransport.ts`, `resources/js/lib/player/listenSender.ts`
- Test: `resources/js/lib/player/listenSender.test.ts`

**Interfaces:**
- Consumes: `ListenPayload` (Task 2); Wayfinder `ListenController.store()` (Task 1).
- Produces:
  - `type PlaybackState = 'unstarted' | 'playing' | 'paused' | 'buffering' | 'ended' | 'cued'`
  - `type TransportEvents = { ready: []; unavailable: []; state: [PlaybackState]; error: [number]; autoplayBlocked: [] }`
  - `type LoadOptions = { startAt: number; autoplay: boolean }`
  - `interface PlayerTransport { load(videoId: string, options: LoadOptions): void; play(): void; pause(): void; seek(seconds: number): void; setVolume(volume: number): void; setMuted(muted: boolean): void; currentTime(): number; duration(): number; videoId(): string | null; on<E extends keyof TransportEvents>(event: E, listener: (...args: TransportEvents[E]) => void): () => void; destroy(): void }`
  - `createEmitter(): { on: PlayerTransport['on']; emit<E extends keyof TransportEvents>(event: E, ...args: TransportEvents[E]): void; clear(): void }`
  - `class FakeTransport implements PlayerTransport` with public `loads: Array<{ videoId: string } & LoadOptions>`, `calls: string[]`, `position: number`, `length: number` (default 180), `currentVideo: string | null`, `volume: number`, `muted: boolean`, and drivers `becomeReady()`, `becomeUnavailable()`, `emitState(state)`, `finish()`, `fail(code)`, `blockAutoplay()`. `play()` emits `playing`, `pause()` emits `paused`, `load()` emits `playing` (autoplay) or `cued`.
  - `type ListenSender = { send(payload: ListenPayload): Promise<boolean>; sendOnUnload(payload: ListenPayload): void }`
  - `createListenSender(deps?: { url?: () => string; request?: (url: string, payload: ListenPayload) => Promise<{ status: number }>; fetcher?: typeof fetch; cookie?: () => string }): ListenSender`
  - `readXsrfToken(cookie: string): string | null`

- [ ] **Step 1: Write the failing sender tests**

`resources/js/lib/player/listenSender.test.ts`:

```ts
import { describe, expect, it, vi } from 'vite-plus/test';
import { createListenSender, readXsrfToken } from '@/lib/player/listenSender';
import type { ListenPayload } from '@/lib/player/listenTracker';

const payload: ListenPayload = {
    id: 'listen-1',
    youtube_video_id: 'abc',
    title: 'Survival',
    artists: 'Muse',
    youtube_playlist_id: 'PL1',
    origin: 'playlist',
    end_reason: 'skipped',
    started_at: '2026-10-01T10:00:00.000Z',
    ended_at: '2026-10-01T10:00:30.000Z',
    position_seconds: 30,
    listened_seconds: 30,
    duration_seconds: 258,
};

describe('listenSender', () => {
    it('posts the listen and reports success on 204', async () => {
        const request = vi.fn(async () => ({ status: 204 }));
        const sender = createListenSender({ url: () => '/listens', request });

        await expect(sender.send(payload)).resolves.toBe(true);
        expect(request).toHaveBeenCalledWith('/listens', payload);
    });

    it('reports failure instead of throwing when the request fails', async () => {
        const sender = createListenSender({
            url: () => '/listens',
            request: async () => {
                throw new Error('offline');
            },
        });

        await expect(sender.send(payload)).resolves.toBe(false);
    });

    it('reports failure on a validation error', async () => {
        const sender = createListenSender({
            url: () => '/listens',
            request: async () => ({ status: 422 }),
        });

        await expect(sender.send(payload)).resolves.toBe(false);
    });

    it('sends on unload with keepalive and the XSRF token from the cookie', () => {
        const fetcher = vi.fn(async () => new Response(null, { status: 204 }));
        const sender = createListenSender({
            url: () => '/listens',
            fetcher,
            cookie: () => 'other=1; XSRF-TOKEN=abc%3D%3D; session=x',
        });

        sender.sendOnUnload(payload);

        expect(fetcher).toHaveBeenCalledWith('/listens', {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': 'abc==',
            },
            body: JSON.stringify(payload),
        });
    });

    it('reads no token when the cookie is missing', () => {
        expect(readXsrfToken('session=x')).toBeNull();
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vp test --project unit resources/js/lib/player/listenSender.test.ts`
Expected: FAIL — cannot resolve `@/lib/player/listenSender`.

- [ ] **Step 3: Implement the transport contract**

`resources/js/lib/player/transport.ts`:

```ts
/**
 * What the player store needs from a playback engine. The YouTube IFrame
 * implementation lives in youtubeTransport.ts; tests use FakeTransport.
 */

export type PlaybackState =
    | 'unstarted'
    | 'playing'
    | 'paused'
    | 'buffering'
    | 'ended'
    | 'cued';

export type TransportEvents = {
    ready: [];
    unavailable: [];
    state: [PlaybackState];
    error: [number];
    autoplayBlocked: [];
};

export type LoadOptions = { startAt: number; autoplay: boolean };

type Listener<E extends keyof TransportEvents> = (
    ...args: TransportEvents[E]
) => void;

export interface PlayerTransport {
    load(videoId: string, options: LoadOptions): void;
    play(): void;
    pause(): void;
    seek(seconds: number): void;
    setVolume(volume: number): void;
    setMuted(muted: boolean): void;
    currentTime(): number;
    duration(): number;
    videoId(): string | null;
    on<E extends keyof TransportEvents>(
        event: E,
        listener: Listener<E>,
    ): () => void;
    destroy(): void;
}

export function createEmitter() {
    const listeners = new Map<keyof TransportEvents, Set<Listener<never>>>();

    function on<E extends keyof TransportEvents>(
        event: E,
        listener: Listener<E>,
    ): () => void {
        const set = listeners.get(event) ?? new Set<Listener<never>>();
        set.add(listener as Listener<never>);
        listeners.set(event, set);

        return () => set.delete(listener as Listener<never>);
    }

    function emit<E extends keyof TransportEvents>(
        event: E,
        ...args: TransportEvents[E]
    ): void {
        for (const listener of [...(listeners.get(event) ?? [])]) {
            (listener as Listener<E>)(...args);
        }
    }

    return { on, emit, clear: () => listeners.clear() };
}
```

- [ ] **Step 4: Implement the fake transport**

`resources/js/lib/player/fakeTransport.ts`:

```ts
import { createEmitter } from '@/lib/player/transport';
import type {
    LoadOptions,
    PlaybackState,
    PlayerTransport,
} from '@/lib/player/transport';

/**
 * In-memory transport for tests. Commands behave like a cooperative
 * player (play emits "playing", load with autoplay emits "playing");
 * the driver methods simulate what YouTube would report.
 */
export class FakeTransport implements PlayerTransport {
    loads: Array<{ videoId: string } & LoadOptions> = [];
    calls: string[] = [];
    position = 0;
    length = 180;
    currentVideo: string | null = null;
    volume = 100;
    muted = false;

    private readonly emitter = createEmitter();

    on: PlayerTransport['on'] = (event, listener) =>
        this.emitter.on(event, listener);

    load(videoId: string, options: LoadOptions): void {
        this.loads.push({ videoId, ...options });
        this.calls.push(`load:${videoId}`);
        this.currentVideo = videoId;
        this.position = options.startAt;
        this.emitter.emit('state', options.autoplay ? 'playing' : 'cued');
    }

    play(): void {
        this.calls.push('play');
        this.emitter.emit('state', 'playing');
    }

    pause(): void {
        this.calls.push('pause');
        this.emitter.emit('state', 'paused');
    }

    seek(seconds: number): void {
        this.calls.push(`seek:${seconds}`);
        this.position = seconds;
    }

    setVolume(volume: number): void {
        this.volume = volume;
    }

    setMuted(muted: boolean): void {
        this.muted = muted;
    }

    currentTime(): number {
        return this.position;
    }

    duration(): number {
        return this.currentVideo === null ? 0 : this.length;
    }

    videoId(): string | null {
        return this.currentVideo;
    }

    destroy(): void {
        this.calls.push('destroy');
        this.emitter.clear();
    }

    becomeReady(): void {
        this.emitter.emit('ready');
    }

    becomeUnavailable(): void {
        this.emitter.emit('unavailable');
    }

    emitState(state: PlaybackState): void {
        this.emitter.emit('state', state);
    }

    finish(): void {
        this.position = this.length;
        this.emitter.emit('state', 'ended');
    }

    fail(code: number): void {
        this.emitter.emit('error', code);
    }

    blockAutoplay(): void {
        this.emitter.emit('autoplayBlocked');
    }
}
```

- [ ] **Step 5: Implement the sender**

`resources/js/lib/player/listenSender.ts`:

```ts
import { http } from '@inertiajs/vue3';
import ListenController from '@/actions/App/Http/Controllers/ListenController';
import type { ListenPayload } from '@/lib/player/listenTracker';

export type ListenSender = {
    send(payload: ListenPayload): Promise<boolean>;
    sendOnUnload(payload: ListenPayload): void;
};

type SenderDeps = {
    url?: () => string;
    request?: (url: string, payload: ListenPayload) => Promise<{ status: number }>;
    fetcher?: typeof fetch;
    cookie?: () => string;
};

export function readXsrfToken(cookie: string): string | null {
    const prefix = 'XSRF-TOKEN=';
    const part = cookie
        .split(';')
        .map((piece) => piece.trim())
        .find((piece) => piece.startsWith(prefix));

    return part ? decodeURIComponent(part.slice(prefix.length)) : null;
}

/**
 * Sends finished listens. `send` goes through Inertia's HTTP client, which
 * adds the X-XSRF-TOKEN header itself. `sendOnUnload` uses fetch with
 * keepalive because an XHR does not survive `pagehide`.
 */
export function createListenSender(deps: SenderDeps = {}): ListenSender {
    const url = deps.url ?? (() => ListenController.store().url);
    const request =
        deps.request ??
        ((target: string, payload: ListenPayload) =>
            http.getClient().request({
                method: 'post',
                url: target,
                data: payload,
                headers: { Accept: 'application/json' },
            }));
    const fetcher = deps.fetcher ?? ((...args: Parameters<typeof fetch>) => fetch(...args));
    const cookie = deps.cookie ?? (() => document.cookie);

    return {
        async send(payload) {
            try {
                const response = await request(url(), payload);

                return response.status >= 200 && response.status < 300;
            } catch {
                return false;
            }
        },
        sendOnUnload(payload) {
            const token = readXsrfToken(cookie());

            void fetcher(url(), {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(token ? { 'X-XSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify(payload),
            }).catch(() => undefined);
        },
    };
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vp test --project unit resources/js/lib/player/listenSender.test.ts`
Expected: PASS (5 tests). If TypeScript rejects `method: 'post'`, check `Method` in `node_modules/@inertiajs/core/types/types.d.ts` and use its exact casing.

- [ ] **Step 7: Commit**

```bash
git add resources/js/lib/player/transport.ts resources/js/lib/player/fakeTransport.ts resources/js/lib/player/listenSender.ts resources/js/lib/player/listenSender.test.ts
git commit -m "feat(player): add the transport contract and the listen sender"
```

---

### Task 4: Player store on a real transport

**Files:**
- Modify: `resources/js/composables/usePlayer.ts` (full rewrite, public API kept)
- Test: `resources/js/composables/usePlayer.test.ts`

**Interfaces:**
- Consumes: Tasks 2 and 3 (`startListen`, `recordProgress`, `markSeek`, `finishListen`, `ListenPayload`, `PlayerTransport`, `PlaybackState`, `ListenSender`, `createListenSender`, `FakeTransport` in tests).
- Produces (used by Tasks 5–7):
  - `createPlayer(deps: PlayerDeps): Player`, `type PlayerDeps = { sender: ListenSender; storage?: Pick<Storage, 'getItem' | 'setItem'> | null; now?: () => number; notify?: (message: string) => void }`
  - `type Player = ReturnType<typeof createPlayer>`; `playerKey: InjectionKey<Player>`; `usePlayer(): Player`
  - `Player` members: `state` (adds `duration`, `origin`, `ready`, `unavailable`, `actualVideoId` to the existing fields), `debug: { entries: DebugEntry[] }`, `current`, `progress`, `totalSeconds`, `upNext`, `diagnostics` (computed `{ expectedVideoId: string | null; actualVideoId: string | null; playerDuration: number; storedDuration: number | null; isLikelyAd: boolean }`), `attachTransport(t)`, `playTracks(tracks, startAt, source, origin = 'playlist')`, `playNext(track, source, origin)`, `jumpTo(index)`, `next()`, `previous()`, `toggle()`, `seek(seconds)`, `setVolume(v)`, `toggleMute()`, `toggleQueue()`, `handlePageHide()`, `isPlayingFrom(playlistId)`.
  - `type DebugEntry = { at: number; kind: 'load' | 'state' | 'error' | 'send' | 'info'; message: string }`
  - Existing exports kept: `QueueTrack` (gains `playlistId: string | null`, the playlist the track was queued from), `QueueSource`, `toQueueTrack(track, position, playlistId = null)`, `formatSeconds`.
  - Notify message keys (translated by the default notifier): `'Player unavailable'`, `"This track can't be played here, skipping it."`

- [ ] **Step 1: Write the failing store tests**

`resources/js/composables/usePlayer.test.ts`:

```ts
import { afterEach, beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { createPlayer } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';
import type { ListenPayload } from '@/lib/player/listenTracker';

const SOURCE = { playlistId: 'PL1', title: 'Road trip' };

function track(
    videoId: string | null,
    overrides: Partial<App.Data.TrackData> = {},
): App.Data.TrackData {
    return {
        videoId,
        title: `Title ${videoId}`,
        artists: 'Artist',
        album: null,
        duration: '3:00',
        durationSeconds: 180,
        thumbnailUrl: null,
        isExplicit: false,
        isAvailable: true,
        ...overrides,
    };
}

function memoryStorage() {
    const data = new Map<string, string>();

    return {
        getItem: (key: string) => data.get(key) ?? null,
        setItem: (key: string, value: string) => {
            data.set(key, value);
        },
    };
}

function setup(options: { storage?: ReturnType<typeof memoryStorage>; ready?: boolean } = {}) {
    const sent: ListenPayload[] = [];
    const unloaded: ListenPayload[] = [];
    const notices: string[] = [];
    const player = createPlayer({
        sender: {
            send: async (payload) => {
                sent.push(payload);

                return true;
            },
            sendOnUnload: (payload) => {
                unloaded.push(payload);
            },
        },
        storage: options.storage ?? memoryStorage(),
        now: () => Date.now(),
        notify: (message) => notices.push(message),
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);

    if (options.ready ?? true) {
        transport.becomeReady();
    }

    return { player, transport, sent, unloaded, notices };
}

/** Plays `seconds` of media with the wall clock moving in step. */
function listen(transport: FakeTransport, seconds: number): void {
    for (let step = 0; step < seconds * 4; step++) {
        transport.position += 0.25;
        vi.advanceTimersByTime(250);
    }
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-01T10:00:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('usePlayer', () => {
    it('loads the chosen track and plays it', () => {
        const { player, transport } = setup();

        player.playTracks([track('a'), track('b'), track('c')], 1, SOURCE);

        expect(transport.loads.at(-1)).toEqual({ videoId: 'b', startAt: 0, autoplay: true });
        expect(player.current.value?.videoId).toBe('b');
        expect(player.state.playing).toBe(true);
    });

    it('moves on at the end of a track and records it as ended', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 10);
        transport.finish();

        expect(sent[0]).toMatchObject({
            youtube_video_id: 'a',
            youtube_playlist_id: 'PL1',
            origin: 'playlist',
            end_reason: 'ended',
            listened_seconds: 10,
            position_seconds: 180,
        });
        expect(transport.loads.at(-1)?.videoId).toBe('b');

        transport.finish();
        expect(sent[1]).toMatchObject({ youtube_video_id: 'b', origin: 'autoplay', end_reason: 'ended' });
        expect(player.state.playing).toBe(false);
    });

    it('records a skip and starts the next listen from the queue', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 5);
        player.next();
        player.next();

        expect(sent[0]).toMatchObject({ youtube_video_id: 'a', end_reason: 'skipped', listened_seconds: 5, position_seconds: 5 });
        expect(sent[1]).toMatchObject({ youtube_video_id: 'b', end_reason: 'skipped', origin: 'queue' });
        expect(player.state.playing).toBe(false);
    });

    it('goes back a track within the first three seconds', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 1, SOURCE);

        listen(transport, 2);
        player.previous();

        expect(sent[0]).toMatchObject({ youtube_video_id: 'b', end_reason: 'previous' });
        expect(transport.loads.at(-1)?.videoId).toBe('a');
    });

    it('restarts the same listen after three seconds', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 5);
        player.previous();

        expect(sent).toHaveLength(0);
        expect(transport.position).toBe(0);

        listen(transport, 2);
        player.next();
        expect(sent[0]).toMatchObject({ youtube_video_id: 'a', end_reason: 'skipped', listened_seconds: 7 });
    });

    it('records a jump when a queued track is picked', () => {
        const { player, sent } = setup();
        player.playTracks([track('a'), track('b'), track('c')], 0, SOURCE);

        player.jumpTo(2);
        player.next();

        expect(sent[0]).toMatchObject({ youtube_video_id: 'a', end_reason: 'jumped' });
        expect(sent[1]).toMatchObject({ youtube_video_id: 'c', origin: 'queue' });
    });

    it('records a pick when a suggestion interrupts the queue', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 3);
        player.playNext(track('z'), { playlistId: null, title: 'Fresh finds' }, 'suggestion');
        player.next();

        expect(sent[0]).toMatchObject({ youtube_video_id: 'a', end_reason: 'picked' });
        expect(sent[1]).toMatchObject({ youtube_video_id: 'z', origin: 'suggestion', youtube_playlist_id: null });
    });

    it('records a replacement when another playlist starts', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 1);
        player.playTracks([track('c')], 0, { playlistId: 'PL2', title: 'Focus' });

        expect(sent[0]).toMatchObject({ youtube_video_id: 'a', end_reason: 'replaced' });
        expect(player.current.value?.videoId).toBe('c');
    });

    it('skips a track YouTube refuses to embed', () => {
        const { player, transport, sent, notices } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        transport.fail(150);

        expect(sent[0]).toMatchObject({ youtube_video_id: 'a', end_reason: 'error', listened_seconds: 0 });
        expect(transport.loads.at(-1)?.videoId).toBe('b');
        expect(notices).toEqual(["This track can't be played here, skipping it."]);
    });

    it('stops when the last track fails', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        transport.fail(101);

        expect(sent[0]).toMatchObject({ end_reason: 'error' });
        expect(transport.loads).toHaveLength(1);
        expect(player.state.playing).toBe(false);
    });

    it('does not count paused time', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 5);
        player.toggle();
        vi.advanceTimersByTime(10_000);
        player.toggle();
        listen(transport, 5);
        player.next();

        expect(sent[0]).toMatchObject({ listened_seconds: 10 });
    });

    it('sends the listen in progress when the page is closed', () => {
        const { player, transport, unloaded } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        listen(transport, 42);
        player.handlePageHide();

        expect(unloaded[0]).toMatchObject({ youtube_video_id: 'a', end_reason: 'abandoned', listened_seconds: 42 });
    });

    it('restores the track paused where it was, keeping its origin', () => {
        const storage = memoryStorage();
        const first = setup({ storage });
        first.player.playTracks([track('a'), track('b')], 1, SOURCE);
        listen(first.transport, 42);
        first.player.handlePageHide();

        const second = setup({ storage });

        expect(second.transport.loads.at(-1)).toEqual({ videoId: 'b', startAt: 42, autoplay: false });
        expect(second.player.state.playing).toBe(false);

        second.player.toggle();
        second.player.next();
        expect(second.sent[0]).toMatchObject({ youtube_video_id: 'b', origin: 'playlist' });
    });

    it('ignores play until the player is ready, then cues the current track', () => {
        const { player, transport } = setup({ ready: false });
        player.playTracks([track('a')], 0, SOURCE);
        transport.calls.length = 0;

        player.toggle();
        expect(transport.calls).toEqual([]);

        transport.becomeReady();
        expect(transport.loads.at(-1)).toEqual({ videoId: 'a', startAt: 0, autoplay: false });
    });

    it('skips tracks without a video id', () => {
        const { player, transport, sent } = setup();

        player.playTracks([track(null), track('b')], 0, SOURCE);

        expect(transport.loads.map((load) => load.videoId)).toEqual(['b']);
        expect(sent).toHaveLength(0);
    });

    it('stops cleanly when no track has a video id', () => {
        const { player, transport } = setup();

        player.playTracks([track(null), track(null)], 0, SOURCE);

        expect(transport.loads).toHaveLength(0);
        expect(player.state.playing).toBe(false);
    });

    it('records each track of a rapid double skip', () => {
        const { player, sent } = setup();
        player.playTracks([track('a'), track('b'), track('c')], 0, SOURCE);

        player.next();
        player.next();

        expect(sent.map((listen) => [listen.youtube_video_id, listen.end_reason, listen.listened_seconds])).toEqual([
            ['a', 'skipped', 0],
            ['b', 'skipped', 0],
        ]);
    });

    it('flags the player as unavailable when YouTube cannot load', () => {
        const { player, transport, notices } = setup({ ready: false });

        transport.becomeUnavailable();

        expect(player.state.unavailable).toBe(true);
        expect(notices).toEqual(['Player unavailable']);
    });

    it('treats a blocked autoplay as paused', () => {
        const { player, transport } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        transport.blockAutoplay();

        expect(player.state.playing).toBe(false);
    });

    it('drives volume, mute and seek through the transport', () => {
        const { player, transport } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        player.setVolume(30);
        player.toggleMute();
        player.seek(90);

        expect(transport.volume).toBe(30);
        expect(transport.muted).toBe(true);
        expect(transport.position).toBe(90);
        expect(player.state.elapsed).toBe(90);
    });

    it('flags a likely ad when another video plays', () => {
        const { player, transport } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        transport.currentVideo = 'ad-video';
        vi.advanceTimersByTime(250);

        expect(player.diagnostics.value).toMatchObject({ expectedVideoId: 'a', actualVideoId: 'ad-video', isLikelyAd: true });
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vp test --project unit resources/js/composables/usePlayer.test.ts`
Expected: FAIL — `createPlayer` is not exported.

- [ ] **Step 3: Rewrite the store**

Replace `resources/js/composables/usePlayer.ts` entirely:

```ts
import { trans } from 'laravel-vue-i18n';
import { computed, getCurrentInstance, inject, reactive, watch } from 'vue';
import type { InjectionKey } from 'vue';
import { useToast } from '@/composables/useToast';
import { createListenSender } from '@/lib/player/listenSender';
import type { ListenSender } from '@/lib/player/listenSender';
import {
    finishListen,
    markSeek,
    recordProgress,
    startListen,
} from '@/lib/player/listenTracker';
import type { Listen } from '@/lib/player/listenTracker';
import type { PlaybackState, PlayerTransport } from '@/lib/player/transport';

/**
 * The player store: queue, current track and listening history, driving a
 * PlayerTransport (the hidden YouTube iframe in the app, a fake in tests).
 * Components reach it through usePlayer(); tests build one with createPlayer().
 */

export type QueueTrack = {
    key: string;
    videoId: string | null;
    title: string;
    artists: string;
    album: string | null;
    duration: string | null;
    durationSeconds: number;
    thumbnailUrl: string | null;
    playlistId: string | null;
};

export type QueueSource = {
    playlistId: string | null;
    title: string;
};

export type DebugEntry = {
    at: number;
    kind: 'load' | 'state' | 'error' | 'send' | 'info';
    message: string;
};

export type PlayerDeps = {
    sender: ListenSender;
    storage?: Pick<Storage, 'getItem' | 'setItem'> | null;
    now?: () => number;
    notify?: (message: string) => void;
};

type PlayerState = {
    queue: QueueTrack[];
    index: number;
    playing: boolean;
    elapsed: number;
    duration: number;
    volume: number;
    muted: boolean;
    source: QueueSource | null;
    origin: App.Enums.ListenOrigin;
    queueOpen: boolean;
    ready: boolean;
    unavailable: boolean;
    actualVideoId: string | null;
};

type PersistedState = Pick<
    PlayerState,
    'queue' | 'index' | 'elapsed' | 'volume' | 'muted' | 'source' | 'origin' | 'queueOpen'
>;

const STORAGE_KEY = 'sonder.player.v1';
const POLL_MS = 250;
const RESTART_THRESHOLD_SECONDS = 3;
const FALLBACK_SECONDS = 210;
const MAX_STORED_TRACKS = 300;
const MAX_DEBUG_ENTRIES = 200;
const AD_DURATION_TOLERANCE_SECONDS = 5;

function secondsOf(track: App.Data.TrackData): number {
    if (track.durationSeconds) {
        return track.durationSeconds;
    }

    const parts = (track.duration ?? '').split(':').map(Number);

    if (parts.length < 2 || parts.some(Number.isNaN)) {
        return FALLBACK_SECONDS;
    }

    return parts.reduce((total, part) => total * 60 + part, 0);
}

export function toQueueTrack(
    track: App.Data.TrackData,
    position: number,
    playlistId: string | null = null,
): QueueTrack {
    return {
        key: `${track.videoId ?? track.title}#${position}`,
        videoId: track.videoId,
        title: track.title,
        artists: track.artists,
        album: track.album,
        duration: track.duration,
        durationSeconds: secondsOf(track),
        thumbnailUrl: track.thumbnailUrl,
        playlistId,
    };
}

export function createPlayer(deps: PlayerDeps) {
    const now = deps.now ?? (() => Date.now());
    const notify = deps.notify ?? (() => undefined);

    const state = reactive<PlayerState>({
        queue: [],
        index: -1,
        playing: false,
        elapsed: 0,
        duration: 0,
        volume: 70,
        muted: false,
        source: null,
        origin: 'playlist',
        queueOpen: false,
        ready: false,
        unavailable: false,
        actualVideoId: null,
    });
    const debug = reactive<{ entries: DebugEntry[] }>({ entries: [] });

    let transport: PlayerTransport | null = null;
    let listen: Listen | null = null;
    let poll: ReturnType<typeof setInterval> | null = null;

    const current = computed<QueueTrack | null>(
        () => state.queue[state.index] ?? null,
    );
    const totalSeconds = computed(() =>
        state.duration > 0 ? state.duration : (current.value?.durationSeconds ?? 0),
    );
    const progress = computed(() =>
        totalSeconds.value > 0 ? Math.min(1, state.elapsed / totalSeconds.value) : 0,
    );
    const upNext = computed(() => state.queue.slice(state.index + 1));
    const diagnostics = computed(() => {
        const expectedVideoId = current.value?.videoId ?? null;
        const storedDuration = current.value?.duration
            ? current.value.durationSeconds
            : null;
        const isOtherVideo =
            state.actualVideoId !== null &&
            expectedVideoId !== null &&
            state.actualVideoId !== expectedVideoId;
        const isDurationOff =
            state.duration > 0 &&
            storedDuration !== null &&
            Math.abs(state.duration - storedDuration) > AD_DURATION_TOLERANCE_SECONDS;

        return {
            expectedVideoId,
            actualVideoId: state.actualVideoId,
            playerDuration: state.duration,
            storedDuration,
            isLikelyAd: state.playing && (isOtherVideo || isDurationOff),
        };
    });

    function log(kind: DebugEntry['kind'], message: string): void {
        debug.entries.push({ at: now(), kind, message });

        if (debug.entries.length > MAX_DEBUG_ENTRIES) {
            debug.entries.splice(0, debug.entries.length - MAX_DEBUG_ENTRIES);
        }
    }

    function persist(): void {
        if (!deps.storage) {
            return;
        }

        const saved: PersistedState = {
            queue: state.queue.slice(0, MAX_STORED_TRACKS),
            index: state.index,
            elapsed: Math.floor(state.elapsed),
            volume: state.volume,
            muted: state.muted,
            source: state.source,
            origin: state.origin,
            queueOpen: state.queueOpen,
        };

        try {
            deps.storage.setItem(STORAGE_KEY, JSON.stringify(saved));
        } catch {
            // Storage can be unavailable (private mode, quota); playback still works.
        }
    }

    function restore(): void {
        if (!deps.storage) {
            return;
        }

        try {
            const saved = JSON.parse(
                deps.storage.getItem(STORAGE_KEY) ?? 'null',
            ) as Partial<PersistedState> | null;

            if (!saved || !Array.isArray(saved.queue)) {
                return;
            }

            state.queue = saved.queue;
            state.index = saved.index ?? -1;
            state.elapsed = saved.elapsed ?? 0;
            state.volume = saved.volume ?? state.volume;
            state.muted = saved.muted ?? state.muted;
            state.source = saved.source ?? null;
            state.origin = saved.origin ?? state.origin;
            state.queueOpen = saved.queueOpen ?? false;
        } catch {
            // A corrupt entry simply starts an empty player.
        }
    }

    function durationOrNull(): number | null {
        return state.duration > 0 ? state.duration : null;
    }

    function beginListen(origin: App.Enums.ListenOrigin): void {
        const track = current.value;

        if (!track?.videoId) {
            return;
        }

        listen = startListen(
            {
                videoId: track.videoId,
                title: track.title,
                artists: track.artists,
                // Tracks restored from an older stored queue have no playlistId.
                playlistId: track.playlistId ?? null,
                origin,
            },
            now(),
        );
    }

    function endListen(reason: App.Enums.ListenEndReason): void {
        if (!listen) {
            return;
        }

        const payload = finishListen(listen, reason, now(), durationOrNull());
        listen = null;
        log('send', `${payload.end_reason} ${payload.youtube_video_id} after ${payload.listened_seconds}s`);

        void deps.sender.send(payload).then((recorded) => {
            if (!recorded) {
                log('error', `listen ${payload.id} was not recorded`);
            }
        });
    }

    function sample(): void {
        if (!transport) {
            return;
        }

        const position = transport.currentTime();
        const duration = transport.duration();

        state.elapsed = position;
        state.actualVideoId = transport.videoId();

        if (duration > 0) {
            state.duration = duration;
        }

        if (listen) {
            recordProgress(listen, position, state.playing, now());
        }
    }

    function startPolling(): void {
        if (poll === null) {
            poll = setInterval(sample, POLL_MS);
        }
    }

    function stopPolling(): void {
        if (poll !== null) {
            clearInterval(poll);
            poll = null;
        }
    }

    function stopAtEnd(): void {
        stopPolling();
        // Pause first: the "paused" event samples the position, which must
        // not overwrite the reset below.
        transport?.pause();
        state.playing = false;
        state.elapsed = 0;
    }

    function loadIndex(index: number, origin: App.Enums.ListenOrigin): void {
        state.index = index;
        state.elapsed = 0;
        state.duration = 0;
        state.origin = origin;

        const track = current.value;

        if (!track) {
            return;
        }

        if (!track.videoId) {
            log('error', `${track.title} has no video id, skipping it`);
            advance();

            return;
        }

        log('load', `${track.videoId} ${track.title} (${origin})`);
        beginListen(origin);

        if (transport && state.ready) {
            transport.load(track.videoId, { startAt: 0, autoplay: true });
        }
    }

    function advance(): void {
        if (state.index + 1 < state.queue.length) {
            loadIndex(state.index + 1, 'autoplay');
        } else {
            stopAtEnd();
        }
    }

    function onState(playback: PlaybackState): void {
        log('state', playback);

        if (playback === 'playing') {
            state.playing = true;

            if (!listen) {
                beginListen(state.origin);
            }

            sample();
            startPolling();

            return;
        }

        if (playback === 'buffering') {
            return;
        }

        sample();
        stopPolling();
        state.playing = false;

        if (playback === 'ended') {
            endListen('ended');
            advance();
        }
    }

    function onError(code: number): void {
        log('error', `YouTube error ${code} on ${current.value?.videoId ?? 'nothing'}`);

        if (!listen) {
            beginListen(state.origin);
        }

        stopPolling();
        state.playing = false;
        endListen('error');
        notify("This track can't be played here, skipping it.");
        advance();
    }

    function attachTransport(next: PlayerTransport): void {
        transport = next;

        next.on('ready', () => {
            state.ready = true;
            log('info', 'player ready');
            next.setVolume(state.volume);
            next.setMuted(state.muted);

            const track = current.value;

            if (track?.videoId) {
                next.load(track.videoId, {
                    startAt: Math.floor(state.elapsed),
                    autoplay: false,
                });
            }
        });
        next.on('unavailable', () => {
            state.unavailable = true;
            log('error', 'the YouTube player could not load');
            notify('Player unavailable');
        });
        next.on('state', onState);
        next.on('error', onError);
        next.on('autoplayBlocked', () => {
            stopPolling();
            state.playing = false;
            log('info', 'autoplay blocked by the browser');
        });
    }

    function playTracks(
        tracks: App.Data.TrackData[],
        startAt: number,
        source: QueueSource,
        origin: App.Enums.ListenOrigin = 'playlist',
    ): void {
        const playable = tracks
            .map((track, position) => ({ track, position }))
            .filter(({ track }) => track.isAvailable);

        if (playable.length === 0) {
            return;
        }

        const start = Math.max(
            0,
            playable.findIndex(({ position }) => position === startAt),
        );

        endListen('replaced');
        state.queue = playable.map(({ track, position }) =>
            toQueueTrack(track, position, source.playlistId),
        );
        state.source = source;
        loadIndex(start, origin);
    }

    function playNext(
        track: App.Data.TrackData,
        source: QueueSource,
        origin: App.Enums.ListenOrigin,
    ): void {
        const item = toQueueTrack(track, Date.now(), source.playlistId);

        if (state.index < 0) {
            state.queue = [item];
            state.source = source;
            loadIndex(0, origin);

            return;
        }

        endListen('picked');
        state.queue.splice(state.index + 1, 0, item);
        loadIndex(state.index + 1, origin);
    }

    function jumpTo(index: number): void {
        if (index < 0 || index >= state.queue.length) {
            return;
        }

        endListen('jumped');
        loadIndex(index, 'queue');
    }

    function next(): void {
        if (!current.value) {
            return;
        }

        endListen('skipped');

        if (state.index + 1 < state.queue.length) {
            loadIndex(state.index + 1, 'queue');
        } else {
            stopAtEnd();
        }
    }

    function seek(seconds: number): void {
        if (!transport || !state.ready) {
            return;
        }

        const target = Math.max(0, seconds);
        transport.seek(target);
        state.elapsed = target;

        if (listen) {
            markSeek(listen, target, now());
        }
    }

    function previous(): void {
        if (!current.value) {
            return;
        }

        if (state.elapsed > RESTART_THRESHOLD_SECONDS || state.index <= 0) {
            seek(0);

            return;
        }

        endListen('previous');
        loadIndex(state.index - 1, 'queue');
    }

    function toggle(): void {
        if (!current.value || !transport || !state.ready) {
            return;
        }

        if (state.playing) {
            transport.pause();
        } else {
            transport.play();
        }
    }

    function setVolume(volume: number): void {
        state.volume = Math.max(0, Math.min(100, volume));
        state.muted = state.volume === 0;
        transport?.setVolume(state.volume);
        transport?.setMuted(state.muted);
    }

    function toggleMute(): void {
        state.muted = !state.muted;
        transport?.setMuted(state.muted);
    }

    function handlePageHide(): void {
        sample();

        if (listen) {
            const payload = finishListen(listen, 'abandoned', now(), durationOrNull());
            listen = null;
            deps.sender.sendOnUnload(payload);
        }

        persist();
    }

    restore();
    watch(
        () => [
            state.queue,
            state.index,
            state.volume,
            state.muted,
            state.source,
            state.origin,
            state.queueOpen,
        ],
        persist,
        { deep: true },
    );

    return {
        state,
        debug,
        current,
        progress,
        totalSeconds,
        upNext,
        diagnostics,
        attachTransport,
        playTracks,
        playNext,
        jumpTo,
        next,
        previous,
        toggle,
        seek,
        setVolume,
        toggleMute,
        toggleQueue: () => {
            state.queueOpen = !state.queueOpen;
        },
        handlePageHide,
        isPlayingFrom: (playlistId: string) =>
            state.source?.playlistId === playlistId && current.value !== null,
    };
}

export type Player = ReturnType<typeof createPlayer>;

export const playerKey: InjectionKey<Player> = Symbol('player');

let defaultPlayer: Player | null = null;

function browserStorage(): Storage | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function getDefaultPlayer(): Player {
    if (defaultPlayer === null) {
        const { toast } = useToast();

        defaultPlayer = createPlayer({
            sender: createListenSender(),
            storage: browserStorage(),
            notify: (message) => toast(trans(message)),
        });
    }

    return defaultPlayer;
}

/**
 * The app-wide player. A component tree can provide its own under
 * `playerKey` (component tests do).
 */
export function usePlayer(): Player {
    const provided = getCurrentInstance() ? inject(playerKey, null) : null;

    return provided ?? getDefaultPlayer();
}

export function formatSeconds(seconds: number): string {
    const whole = Math.max(0, Math.floor(seconds));

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vp test --project unit resources/js/composables/usePlayer.test.ts`
Expected: PASS (22 tests). Note for the "likely ad" test: the fake's `duration()` is 180 and the stored duration is 180, so only the other video id flags it.

- [ ] **Step 5: Pass origins from the pages**

In `resources/js/pages/playlist/Show.vue`, change `player.playTracks(tracks.value, index, source.value);` to:

```ts
    player.playTracks(tracks.value, index, source.value, 'playlist');
```

In `resources/js/pages/discover/Index.vue`, change the `playFind` body to:

```ts
    player.playNext(
        find.track,
        {
            playlistId: null,
            title: trans('Fresh finds'),
        },
        'suggestion',
    );
```

- [ ] **Step 6: Type-check and run all unit tests**

Run: `bun run test:types && vp test --project unit`
Expected: no type errors; all unit tests pass.

- [ ] **Step 7: Commit**

```bash
git add resources/js/composables/usePlayer.ts resources/js/composables/usePlayer.test.ts resources/js/pages/playlist/Show.vue resources/js/pages/discover/Index.vue
git commit -m "feat(player): drive the queue through a transport and track listens"
```

---

### Task 5: YouTube transport and hidden host

**Files:**
- Create: `resources/js/lib/player/youtubeTransport.ts`, `resources/js/components/shell/PlayerHost.vue`
- Modify: `resources/js/layouts/AppLayout.vue`
- Test: `resources/js/components/shell/PlayerHost.browser.test.ts`

**Interfaces:**
- Consumes: `PlayerTransport`, `createEmitter`, `PlaybackState` (Task 3); `usePlayer`, `createPlayer`, `playerKey` (Task 4); `FakeTransport` in tests.
- Produces: `createYouTubeTransport(element: HTMLElement): PlayerTransport`; `PlayerHost` component with optional prop `createTransport?: (element: HTMLElement) => PlayerTransport` (defaults to `createYouTubeTransport`).

- [ ] **Step 1: Write the failing host test**

`resources/js/components/shell/PlayerHost.browser.test.ts`:

```ts
import { expect, it } from 'vite-plus/test';
import { render } from 'vitest-browser-vue';
import PlayerHost from '@/components/shell/PlayerHost.vue';
import { createPlayer, playerKey } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';
import type { ListenPayload } from '@/lib/player/listenTracker';

function mountHost() {
    const unloaded: ListenPayload[] = [];
    const player = createPlayer({
        sender: { send: async () => true, sendOnUnload: (payload) => unloaded.push(payload) },
        storage: null,
    });
    const transport = new FakeTransport();
    let hostElement: HTMLElement | null = null;

    const screen = render(PlayerHost, {
        props: {
            createTransport: (element: HTMLElement) => {
                hostElement = element;

                return transport;
            },
        },
        global: { provide: { [playerKey]: player } },
    });

    return { screen, player, transport, unloaded, host: () => hostElement };
}

it('keeps the iframe host in the page, invisible and out of reach', async () => {
    const { host } = mountHost();

    const wrapper = host()?.parentElement as HTMLElement;
    const style = getComputedStyle(wrapper);
    const box = wrapper.getBoundingClientRect();

    expect(wrapper.getAttribute('aria-hidden')).toBe('true');
    expect(style.display).not.toBe('none');
    expect(style.visibility).not.toBe('hidden');
    expect(style.opacity).toBe('0');
    expect(style.pointerEvents).toBe('none');
    expect(box.width).toBe(1);
    expect(box.height).toBe(1);
    expect(box.right).toBeLessThanOrEqual(0);
});

it('hands its transport to the player', async () => {
    const { player, transport } = mountHost();

    transport.becomeReady();

    expect(player.state.ready).toBe(true);
});

it('sends the listen in progress when the page is hidden', async () => {
    const { player, transport, unloaded } = mountHost();
    transport.becomeReady();
    player.playTracks(
        [{ videoId: 'a', title: 'A', artists: 'X', album: null, duration: '3:00', durationSeconds: 180, thumbnailUrl: null, isExplicit: false, isAvailable: true }],
        0,
        { playlistId: 'PL1', title: 'Mix' },
    );

    window.dispatchEvent(new PageTransitionEvent('pagehide'));

    expect(unloaded[0]).toMatchObject({ youtube_video_id: 'a', end_reason: 'abandoned' });
});

it('destroys its transport when unmounted', async () => {
    const { screen, transport } = mountHost();

    screen.unmount();

    expect(transport.calls).toContain('destroy');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vp test --project browser resources/js/components/shell/PlayerHost.browser.test.ts`
Expected: FAIL — cannot resolve `@/components/shell/PlayerHost.vue`.

- [ ] **Step 3: Implement the YouTube transport**

`resources/js/lib/player/youtubeTransport.ts`:

```ts
import { createEmitter } from '@/lib/player/transport';
import type { PlaybackState, PlayerTransport } from '@/lib/player/transport';

type YouTubePlayer = {
    loadVideoById(options: { videoId: string; startSeconds?: number }): void;
    cueVideoById(options: { videoId: string; startSeconds?: number }): void;
    playVideo(): void;
    pauseVideo(): void;
    seekTo(seconds: number, allowSeekAhead: boolean): void;
    setVolume(volume: number): void;
    mute(): void;
    unMute(): void;
    getCurrentTime(): number;
    getDuration(): number;
    getVideoData(): { video_id?: string };
    destroy(): void;
};

type YouTubePlayerOptions = {
    host: string;
    width: number;
    height: number;
    playerVars: Record<string, string | number>;
    events: {
        onReady: () => void;
        onStateChange: (event: { data: number }) => void;
        onError: (event: { data: number }) => void;
        onAutoplayBlocked: () => void;
    };
};

type YouTubeNamespace = {
    Player: new (
        element: HTMLElement,
        options: YouTubePlayerOptions,
    ) => YouTubePlayer;
};

declare global {
    interface Window {
        YT?: YouTubeNamespace;
        onYouTubeIframeAPIReady?: () => void;
    }
}

const API_URL = 'https://www.youtube.com/iframe_api';
const LOAD_TIMEOUT_MS = 15_000;
const STATES: Record<number, PlaybackState> = {
    [-1]: 'unstarted',
    0: 'ended',
    1: 'playing',
    2: 'paused',
    3: 'buffering',
    5: 'cued',
};

let apiPromise: Promise<YouTubeNamespace> | null = null;

function loadIframeApi(): Promise<YouTubeNamespace> {
    if (window.YT?.Player) {
        return Promise.resolve(window.YT);
    }

    apiPromise ??= new Promise<YouTubeNamespace>((resolve, reject) => {
        const timeout = window.setTimeout(
            () => reject(new Error('The YouTube IFrame API timed out')),
            LOAD_TIMEOUT_MS,
        );
        const previous = window.onYouTubeIframeAPIReady;

        window.onYouTubeIframeAPIReady = () => {
            previous?.();
            window.clearTimeout(timeout);

            if (window.YT?.Player) {
                resolve(window.YT);
            } else {
                reject(new Error('The YouTube IFrame API loaded without YT.Player'));
            }
        };

        const script = document.createElement('script');
        script.src = API_URL;
        script.async = true;
        script.addEventListener('error', () => {
            window.clearTimeout(timeout);
            reject(new Error('The YouTube IFrame API failed to load'));
        });
        document.head.append(script);
    }).catch((error: unknown) => {
        apiPromise = null;

        throw error;
    });

    return apiPromise;
}

/**
 * PlayerTransport over the YouTube IFrame Player API. youtube.com (never
 * youtube-nocookie.com) so the user's YouTube Premium session applies.
 * Commands before the player is ready are ignored; the store waits for
 * "ready" and cues the current track itself.
 */
export function createYouTubeTransport(element: HTMLElement): PlayerTransport {
    const emitter = createEmitter();
    let player: YouTubePlayer | null = null;
    let destroyed = false;

    loadIframeApi().then(
        (YT) => {
            if (destroyed) {
                return;
            }

            const instance = new YT.Player(element, {
                host: 'https://www.youtube.com',
                width: 1,
                height: 1,
                playerVars: {
                    controls: 0,
                    disablekb: 1,
                    playsinline: 1,
                    rel: 0,
                    iv_load_policy: 3,
                    origin: window.location.origin,
                },
                events: {
                    onReady: () => {
                        player = instance;
                        emitter.emit('ready');
                    },
                    onStateChange: ({ data }) => {
                        const state = STATES[data];

                        if (state) {
                            emitter.emit('state', state);
                        }
                    },
                    onError: ({ data }) => emitter.emit('error', data),
                    onAutoplayBlocked: () => emitter.emit('autoplayBlocked'),
                },
            });
        },
        () => {
            if (!destroyed) {
                emitter.emit('unavailable');
            }
        },
    );

    return {
        load(videoId, { startAt, autoplay }) {
            if (autoplay) {
                player?.loadVideoById({ videoId, startSeconds: startAt });
            } else {
                player?.cueVideoById({ videoId, startSeconds: startAt });
            }
        },
        play: () => player?.playVideo(),
        pause: () => player?.pauseVideo(),
        seek: (seconds) => player?.seekTo(seconds, true),
        setVolume: (volume) => player?.setVolume(volume),
        setMuted: (muted) => (muted ? player?.mute() : player?.unMute()),
        currentTime: () => player?.getCurrentTime() ?? 0,
        duration: () => player?.getDuration() ?? 0,
        videoId: () => player?.getVideoData().video_id ?? null,
        on: emitter.on,
        destroy() {
            destroyed = true;
            player?.destroy();
            player = null;
            emitter.clear();
        },
    };
}
```

- [ ] **Step 4: Implement the host and mount it**

`resources/js/components/shell/PlayerHost.vue`:

```vue
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { usePlayer } from '@/composables/usePlayer';
import type { PlayerTransport } from '@/lib/player/transport';
import { createYouTubeTransport } from '@/lib/player/youtubeTransport';

/**
 * Hosts the YouTube iframe: 1px, offscreen, transparent. Not display:none,
 * which stops the player from initialising.
 */
const props = withDefaults(
    defineProps<{
        createTransport?: (element: HTMLElement) => PlayerTransport;
    }>(),
    { createTransport: createYouTubeTransport },
);

const player = usePlayer();
const mount = ref<HTMLDivElement | null>(null);
let transport: PlayerTransport | null = null;

onMounted(() => {
    window.addEventListener('pagehide', player.handlePageHide);

    if (mount.value) {
        transport = props.createTransport(mount.value);
        player.attachTransport(transport);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('pagehide', player.handlePageHide);
    transport?.destroy();
});
</script>

<template>
    <div class="player-host" aria-hidden="true" tabindex="-1">
        <div ref="mount" />
    </div>
</template>

<style scoped>
.player-host {
    position: fixed;
    top: 0;
    left: -10px;
    width: 1px;
    height: 1px;
    overflow: hidden;
    opacity: 0;
    pointer-events: none;
}
</style>
```

In `resources/js/layouts/AppLayout.vue`, add `import PlayerHost from '@/components/shell/PlayerHost.vue';` with the other shell imports, and add `<PlayerHost />` as the last child of the root `<div class="flex h-full">`, after `<CommandPalette />`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vp test --project browser resources/js/components/shell/PlayerHost.browser.test.ts`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add resources/js/lib/player/youtubeTransport.ts resources/js/components/shell/PlayerHost.vue resources/js/components/shell/PlayerHost.browser.test.ts resources/js/layouts/AppLayout.vue
git commit -m "feat(player): play through a hidden YouTube iframe"
```

---

### Task 6: Player bar on real playback

**Files:**
- Modify: `resources/js/components/shell/PlayerBar.vue`, `lang/fr_BE.json`
- Test: `resources/js/components/shell/PlayerBar.browser.test.ts`

**Interfaces:**
- Consumes: `createPlayer`, `playerKey`, `formatSeconds`, `player.seek`, `player.totalSeconds`, `player.state.unavailable` (Task 4); `FakeTransport` (Task 3).
- Produces: a seek hit area with `data-testid="seek-bar"`; controls disabled while the player is unavailable.

- [ ] **Step 1: Write the failing component test**

`resources/js/components/shell/PlayerBar.browser.test.ts`:

```ts
import { expect, it } from 'vite-plus/test';
import { page } from 'vite-plus/test/browser';
import { render } from 'vitest-browser-vue';
import PlayerBar from '@/components/shell/PlayerBar.vue';
import { createPlayer, playerKey } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';

function mountBar() {
    const player = createPlayer({
        sender: { send: async () => true, sendOnUnload: () => undefined },
        storage: null,
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);
    transport.becomeReady();
    player.playTracks(
        [{ videoId: 'a', title: 'Survival', artists: 'Muse', album: null, duration: '3:00', durationSeconds: 180, thumbnailUrl: null, isExplicit: false, isAvailable: true }],
        0,
        { playlistId: 'PL1', title: 'Mix' },
    );

    render(PlayerBar, {
        global: {
            provide: { [playerKey]: player },
            mocks: { $t: (key: string) => key },
        },
    });

    return { player, transport };
}

it('pauses and resumes through the transport', async () => {
    const { transport } = mountBar();

    await page.getByRole('button', { name: 'Pause' }).click();
    expect(transport.calls).toContain('pause');
    await expect.element(page.getByRole('button', { name: 'Play' })).toBeVisible();

    await page.getByRole('button', { name: 'Play' }).click();
    expect(transport.calls).toContain('play');
});

it('seeks where the bar is clicked', async () => {
    const { transport } = mountBar();
    const bar = page.getByTestId('seek-bar');
    const width = bar.element().getBoundingClientRect().width;

    await bar.click({ position: { x: width / 2, y: 6 } });

    expect(transport.position).toBeGreaterThan(80);
    expect(transport.position).toBeLessThan(100);
});

it('sets the volume', async () => {
    const { transport } = mountBar();

    await page.getByRole('slider', { name: 'Volume' }).fill('30');

    expect(transport.volume).toBe(30);
});

it('no longer presents itself as a preview', async () => {
    mountBar();

    await expect.element(page.getByText('Preview player')).not.toBeInTheDocument();
});

it('disables the controls when the player is unavailable', async () => {
    const { player } = mountBar();

    player.state.unavailable = true;

    await expect.element(page.getByRole('button', { name: 'Next  N' })).toBeDisabled();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vp test --project browser resources/js/components/shell/PlayerBar.browser.test.ts`
Expected: FAIL — `seek-bar` not found and "Preview player" present.

- [ ] **Step 3: Update the bar**

In `resources/js/components/shell/PlayerBar.vue`:

1. In `<script setup>`, add below `onVolume`:

```ts
function onSeek(event: MouseEvent): void {
    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();

    if (box.width === 0) {
        return;
    }

    const ratio = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
    player.seek(ratio * player.totalSeconds.value);
}

const controlsDisabled = computed(
    () => !player.current.value || player.state.unavailable,
);
```

2. On the previous, play and next buttons, replace `:disabled="!player.current.value"` with `:disabled="controlsDisabled"`.

3. Replace the progress `<div class="h-1 overflow-hidden rounded-full bg-line" role="progressbar" ...>...</div>` block with a clickable hit area around it:

```vue
                <div
                    class="cursor-pointer py-1.5"
                    data-testid="seek-bar"
                    @click="onSeek"
                >
                    <div
                        class="h-1 overflow-hidden rounded-full bg-line"
                        role="progressbar"
                        :aria-valuenow="Math.round(player.progress.value * 100)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="$t('Playback progress')"
                    >
                        <i
                            class="progress block h-full origin-left"
                            :style="{
                                transform: `scaleX(${player.progress.value})`,
                            }"
                        />
                    </div>
                </div>
```

4. Replace the total time `<span class="text-right">{{ player.current.value?.duration ?? '0:00' }}</span>` with:

```vue
                <span class="text-right">{{
                    formatSeconds(player.totalSeconds.value)
                }}</span>
```

5. Delete the whole `<span class="mr-1 hidden text-xs font-medium text-faint xl:inline" :title="$t('Playback is simulated until Sonder can control YouTube Music')">{{ $t('Preview player') }}</span>` element.

In `lang/fr_BE.json`, delete the `"Playback is simulated until Sonder can control YouTube Music"` and `"Preview player"` entries, and add (keeping the file's alphabetical order):

```json
    "Player unavailable": "Lecteur indisponible",
    "This track can't be played here, skipping it.": "Ce titre ne peut pas être lu ici, on passe au suivant.",
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vp test --project browser resources/js/components/shell/PlayerBar.browser.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/shell/PlayerBar.vue resources/js/components/shell/PlayerBar.browser.test.ts lang/fr_BE.json
git commit -m "feat(player): seek from the bar and drop the preview label"
```

---

### Task 7: Debug panel

**Files:**
- Create: `resources/js/components/shell/PlayerDebugPanel.vue`
- Modify: `resources/js/components/shell/QueuePanel.vue`, `lang/fr_BE.json`
- Delete: `public/player-test.html` (untracked; plain `rm`)
- Test: `resources/js/components/shell/PlayerDebugPanel.browser.test.ts`

**Interfaces:**
- Consumes: `player.state`, `player.debug.entries`, `player.diagnostics`, `formatSeconds`, `createPlayer`, `playerKey` (Task 4); `FakeTransport` (Task 3).
- Produces: `PlayerDebugPanel` (no props), opened by a "Debug" button at the bottom of `QueuePanel`.

- [ ] **Step 1: Write the failing component test**

`resources/js/components/shell/PlayerDebugPanel.browser.test.ts`:

```ts
import { expect, it } from 'vite-plus/test';
import { page } from 'vite-plus/test/browser';
import { render } from 'vitest-browser-vue';
import PlayerDebugPanel from '@/components/shell/PlayerDebugPanel.vue';
import { createPlayer, playerKey } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';

function mountPanel() {
    const player = createPlayer({
        sender: { send: async () => true, sendOnUnload: () => undefined },
        storage: null,
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);
    transport.becomeReady();
    player.playTracks(
        [
            { videoId: 'abc', title: 'Survival', artists: 'Muse', album: null, duration: '3:00', durationSeconds: 180, thumbnailUrl: null, isExplicit: false, isAvailable: true },
            { videoId: 'def', title: 'Psycho', artists: 'Muse', album: null, duration: '5:17', durationSeconds: 317, thumbnailUrl: null, isExplicit: false, isAvailable: true },
        ],
        0,
        { playlistId: 'PL1', title: 'Mix' },
    );

    render(PlayerDebugPanel, {
        global: {
            provide: { [playerKey]: player },
            mocks: { $t: (key: string) => key },
        },
    });

    return { player, transport };
}

it('shows the player state and the expected video', async () => {
    mountPanel();

    await expect.element(page.getByText('playing', { exact: true })).toBeVisible();
    await expect.element(page.getByText('abc', { exact: true }).first()).toBeVisible();
});

it('flags a likely ad when another video is playing', async () => {
    const { transport } = mountPanel();

    transport.currentVideo = 'ad-video';
    transport.emitState('playing');

    await expect.element(page.getByText('Likely ad')).toBeVisible();
});

it('lists player errors', async () => {
    const { transport } = mountPanel();

    transport.fail(150);

    await expect.element(page.getByText(/YouTube error 150 on abc/)).toBeVisible();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vp test --project browser resources/js/components/shell/PlayerDebugPanel.browser.test.ts`
Expected: FAIL — cannot resolve `@/components/shell/PlayerDebugPanel.vue`.

- [ ] **Step 3: Implement the panel**

`resources/js/components/shell/PlayerDebugPanel.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { formatSeconds, usePlayer } from '@/composables/usePlayer';

const player = usePlayer();

const VISIBLE_ENTRIES = 50;

const entries = computed(() =>
    player.debug.entries.slice(-VISIBLE_ENTRIES).reverse(),
);
const status = computed(() => {
    if (player.state.unavailable) {
        return 'unavailable';
    }

    if (!player.state.ready) {
        return 'loading';
    }

    return player.state.playing ? 'playing' : 'paused';
});

function timeOf(at: number): string {
    return new Date(at).toLocaleTimeString();
}
</script>

<template>
    <section
        class="rounded-[10px] bg-raised p-3 text-xs"
        :aria-label="$t('Player debug')"
    >
        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
            <dt class="text-faint">{{ $t('State') }}</dt>
            <dd>{{ status }}</dd>
            <dt class="text-faint">{{ $t('Expected video') }}</dt>
            <dd class="font-mono">
                {{ player.diagnostics.value.expectedVideoId ?? '—' }}
            </dd>
            <dt class="text-faint">{{ $t('Playing video') }}</dt>
            <dd class="font-mono">
                {{ player.diagnostics.value.actualVideoId ?? '—' }}
            </dd>
            <dt class="text-faint">{{ $t('Player duration') }}</dt>
            <dd>{{ formatSeconds(player.diagnostics.value.playerDuration) }}</dd>
            <dt class="text-faint">{{ $t('Stored duration') }}</dt>
            <dd>
                {{
                    player.diagnostics.value.storedDuration === null
                        ? '—'
                        : formatSeconds(player.diagnostics.value.storedDuration)
                }}
            </dd>
        </dl>

        <p
            v-if="player.diagnostics.value.isLikelyAd"
            class="mt-2 inline-block rounded-full bg-alarm px-2 py-0.5 font-semibold text-paper"
        >
            {{ $t('Likely ad') }}
        </p>

        <ol
            class="mt-3 max-h-60 space-y-0.5 overflow-y-auto font-mono text-[11px]"
        >
            <li
                v-for="(entry, position) in entries"
                :key="`${entry.at}-${position}`"
                :class="{ 'text-alarm': entry.kind === 'error' }"
            >
                <span class="text-faint">{{ timeOf(entry.at) }}</span>
                {{ entry.kind }} · {{ entry.message }}
            </li>
            <li v-if="entries.length === 0" class="text-faint">
                {{ $t('No events yet.') }}
            </li>
        </ol>
    </section>
</template>
```

- [ ] **Step 4: Add the link in the queue panel**

In `resources/js/components/shell/QueuePanel.vue`:

1. In `<script setup>`, add `import { ref } from 'vue';` and `import PlayerDebugPanel from '@/components/shell/PlayerDebugPanel.vue';` with the other imports, and below `const VISIBLE_UP_NEXT = 40;`:

```ts
const debugOpen = ref(false);
```

2. At the end of the inner `<div class="h-full w-[340px] overflow-y-auto px-3 pt-4 pb-32">`, after the "This is the last track." paragraph:

```vue
            <button
                type="button"
                class="mt-6 px-2 text-xs text-faint transition-colors hover:text-dim"
                :aria-expanded="debugOpen"
                @click="debugOpen = !debugOpen"
            >
                {{ $t('Debug') }}
            </button>
            <PlayerDebugPanel v-if="debugOpen" class="mt-2" />
```

In `lang/fr_BE.json`, add (alphabetical order):

```json
    "Debug": "Debug",
    "Expected video": "Vidéo attendue",
    "Likely ad": "Pub probable",
    "No events yet.": "Aucun événement pour l'instant.",
    "Player debug": "Debug du lecteur",
    "Player duration": "Durée selon le lecteur",
    "Playing video": "Vidéo en lecture",
    "State": "État",
    "Stored duration": "Durée enregistrée",
```

(Skip any key that already exists in the file.)

- [ ] **Step 5: Run the test to verify it passes, then remove the throwaway page**

Run: `vp test --project browser resources/js/components/shell/PlayerDebugPanel.browser.test.ts && rm public/player-test.html`
Expected: PASS (3 tests); the file is gone (it was never tracked).

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/shell/PlayerDebugPanel.vue resources/js/components/shell/PlayerDebugPanel.browser.test.ts resources/js/components/shell/QueuePanel.vue lang/fr_BE.json
git commit -m "feat(player): add a debug panel behind the queue"
```

---

### Task 8: Whole-branch verification

**Files:** none new (fixes only, if a check fails).

- [ ] **Step 1: Run every automated check**

Run: `vp test && bun run test:lint && bun run test:types && ./vendor/bin/pest --tia --parallel`
Expected: all Vitest projects pass, lint and types clean, all Pest tests pass. Fix any failure in the task that owns the file, then re-run.

- [ ] **Step 2: Build the front end**

Run: `bun run build`
Expected: build succeeds (Wayfinder regenerates `resources/js/actions`).

- [ ] **Step 3: Manual check in the real browser**

Ask the user to run `composer run dev` if it is not running, get the URL with the Boost `get-absolute-url` tool for `/playlists`, and give it to the user (they prefer judging playback feel themselves). Checklist to hand over:
1. Start a playlist: sound plays, the bar shows the real duration and progress.
2. Pause/resume, next, previous (before and after 3s), click in the queue, seek on the bar, volume and mute.
3. Switch tabs for a minute: playback continues.
4. Reload mid-track: the track comes back paused at the same position; play resumes it.
5. Open "Debug" at the bottom of the queue: state, expected vs playing video, durations, events.
6. Check listens landed: Boost `database-query` → `select youtube_video_id, origin, end_reason, listened_seconds, position_seconds from listens order by started_at desc limit 10`.

- [ ] **Step 4: Report**

Summarise results to the user, including anything that failed or was skipped. Do not merge or deploy without their go.
